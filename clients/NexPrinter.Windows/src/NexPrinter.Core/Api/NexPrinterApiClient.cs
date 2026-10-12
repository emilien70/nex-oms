using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using NexPrinter.Core.Configuration;
using NexPrinter.Core.Printing;

namespace NexPrinter.Core.Api;

public sealed class NexPrinterApiClient : INexPrinterApiClient
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);
    private readonly HttpClient _httpClient;

    public NexPrinterApiClient(HttpClient httpClient)
    {
        _httpClient = httpClient ?? throw new ArgumentNullException(nameof(httpClient));
    }

    public async Task<PairingResult> PairAsync(
        Uri serverUrl,
        string pairingCode,
        string computerName,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(serverUrl);
        ArgumentException.ThrowIfNullOrWhiteSpace(pairingCode);
        ArgumentException.ThrowIfNullOrWhiteSpace(computerName);

        using var request = CreateRequest(
            HttpMethod.Post,
            serverUrl,
            "api/nex-printer/v1/pair",
            token: null);
        request.Content = JsonContent.Create(new PairingRequest(
            pairingCode.Trim().ToUpperInvariant(),
            computerName.Trim(),
            "windows",
            ClientConstants.ClientVersion), options: JsonOptions);

        using var response = await _httpClient.SendAsync(request, cancellationToken).ConfigureAwait(false);
        await EnsureSuccessAsync(response, cancellationToken).ConfigureAwait(false);
        var payload = await response.Content.ReadFromJsonAsync<PairingResponse>(JsonOptions, cancellationToken)
            .ConfigureAwait(false)
            ?? throw new NexPrinterApiException(response.StatusCode, null, "Serwer zwrócił pustą odpowiedź parowania.");

        if (string.IsNullOrWhiteSpace(payload.Agent.Id) || string.IsNullOrWhiteSpace(payload.Token))
        {
            throw new NexPrinterApiException(response.StatusCode, null, "Serwer zwrócił niepełne dane parowania.");
        }

        return new PairingResult(payload.Agent.Id, payload.Agent.Name, payload.Token);
    }

    public async Task SendHeartbeatAsync(ClientCredentials credentials, CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(credentials);

        using var request = CreateRequest(
            HttpMethod.Post,
            credentials.ServerUrl,
            "api/nex-printer/v1/heartbeat",
            credentials.Token);
        request.Content = JsonContent.Create(
            new HeartbeatRequest(ClientConstants.ClientVersion),
            options: JsonOptions);

        using var response = await _httpClient.SendAsync(request, cancellationToken).ConfigureAwait(false);
        await EnsureSuccessAsync(response, cancellationToken).ConfigureAwait(false);
    }

    public async Task SynchronizePrintersAsync(
        ClientCredentials credentials,
        IReadOnlyCollection<PrinterDescriptor> printers,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(credentials);
        ArgumentNullException.ThrowIfNull(printers);

        using var request = CreateRequest(
            HttpMethod.Put,
            credentials.ServerUrl,
            "api/nex-printer/v1/printers",
            credentials.Token);
        request.Content = JsonContent.Create(
            new PrinterSynchronizationRequest(printers.Select(printer => new PrinterPayload(
                printer.SystemName,
                printer.DisplayName,
                printer.IsDefault,
                new PrinterCapabilities(["pdf"]))).ToArray()),
            options: JsonOptions);

        using var response = await _httpClient.SendAsync(request, cancellationToken).ConfigureAwait(false);
        await EnsureSuccessAsync(response, cancellationToken).ConfigureAwait(false);
    }

    public async Task<ClaimedPrintJob?> ClaimPrintJobAsync(
        ClientCredentials credentials,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(credentials);

        using var request = CreateRequest(
            HttpMethod.Post,
            credentials.ServerUrl,
            "api/nex-printer/v1/jobs/claim",
            credentials.Token);
        using var response = await _httpClient.SendAsync(request, cancellationToken).ConfigureAwait(false);

        if (response.StatusCode == HttpStatusCode.NoContent)
        {
            return null;
        }

        await EnsureSuccessAsync(response, cancellationToken).ConfigureAwait(false);
        var payload = await response.Content.ReadFromJsonAsync<ClaimResponse>(JsonOptions, cancellationToken)
            .ConfigureAwait(false)
            ?? throw new NexPrinterApiException(
                response.StatusCode,
                "invalid_job_response",
                "Serwer zwrócił pustą odpowiedź zadania wydruku.");

        if (!Uri.TryCreate(payload.Job.PayloadUrl, UriKind.Absolute, out var payloadUrl))
        {
            throw new PrintJobException("invalid_payload_url", "Serwer zwrócił nieprawidłowy adres pliku PDF.");
        }

        EnsureSameOrigin(credentials.ServerUrl, payloadUrl);

        var job = new PrintJobDescriptor(
            payload.Job.Id,
            payload.Job.Printer.SystemName,
            payload.Job.Printer.DisplayName,
            payload.Job.DocumentType,
            payload.Job.DocumentNumber,
            payload.Job.Filename,
            payload.Job.ContentType,
            payload.Job.Size,
            payload.Job.Sha256,
            payload.Job.Copies,
            payloadUrl);
        PrintPayloadValidator.ValidateMetadata(job);

        if (string.IsNullOrWhiteSpace(payload.LeaseToken))
        {
            throw new PrintJobException(
                "invalid_job_response",
                "Serwer nie przekazał dzierżawy zadania wydruku.");
        }

        return new ClaimedPrintJob(job, payload.LeaseToken);
    }

    public async Task<byte[]> DownloadPrintJobPayloadAsync(
        ClientCredentials credentials,
        ClaimedPrintJob claimedJob,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(credentials);
        ArgumentNullException.ThrowIfNull(claimedJob);
        EnsureSameOrigin(credentials.ServerUrl, claimedJob.Job.PayloadUrl);
        PrintPayloadValidator.ValidateMetadata(claimedJob.Job);

        using var request = new HttpRequestMessage(HttpMethod.Get, claimedJob.Job.PayloadUrl);
        ConfigureAuthenticatedRequest(request, credentials.Token);
        request.Headers.Accept.Clear();
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/pdf"));
        request.Headers.Add("X-NEX-Printer-Lease", claimedJob.LeaseToken);

        using var response = await _httpClient.SendAsync(
                request,
                HttpCompletionOption.ResponseHeadersRead,
                cancellationToken)
            .ConfigureAwait(false);
        await EnsureSuccessAsync(response, cancellationToken).ConfigureAwait(false);

        if (!string.Equals(
                response.Content.Headers.ContentType?.MediaType,
                "application/pdf",
                StringComparison.OrdinalIgnoreCase))
        {
            throw new PrintJobException("invalid_content_type", "Pobrany plik nie jest dokumentem PDF.");
        }

        if (response.Content.Headers.ContentLength is > PrintPayloadValidator.MaximumPayloadSize)
        {
            throw new PrintJobException("payload_too_large", "Pobrany plik PDF przekracza limit 25 MB.");
        }

        await using var source = await response.Content.ReadAsStreamAsync(cancellationToken).ConfigureAwait(false);
        await using var destination = new MemoryStream();
        var buffer = new byte[81920];
        while (true)
        {
            var read = await source.ReadAsync(buffer, cancellationToken).ConfigureAwait(false);
            if (read == 0)
            {
                break;
            }

            if (destination.Length + read > PrintPayloadValidator.MaximumPayloadSize)
            {
                throw new PrintJobException("payload_too_large", "Pobrany plik PDF przekracza limit 25 MB.");
            }

            await destination.WriteAsync(buffer.AsMemory(0, read), cancellationToken).ConfigureAwait(false);
        }

        var contents = destination.ToArray();
        PrintPayloadValidator.ValidateContents(claimedJob.Job, contents);
        return contents;
    }

    public async Task CompletePrintJobAsync(
        ClientCredentials credentials,
        ClaimedPrintJob claimedJob,
        PrintJobCompletion completion,
        CancellationToken cancellationToken)
    {
        ArgumentNullException.ThrowIfNull(credentials);
        ArgumentNullException.ThrowIfNull(claimedJob);
        ArgumentNullException.ThrowIfNull(completion);

        using var request = CreateRequest(
            HttpMethod.Post,
            credentials.ServerUrl,
            $"api/nex-printer/v1/jobs/{Uri.EscapeDataString(claimedJob.Job.Id)}/complete",
            credentials.Token);
        request.Headers.Add("X-NEX-Printer-Lease", claimedJob.LeaseToken);
        request.Content = JsonContent.Create(new CompletionRequest(
            completion.Status == PrintJobCompletionStatus.Spooled ? "spooled" : "failed",
            completion.ErrorCode,
            completion.ErrorMessage), options: JsonOptions);

        using var response = await _httpClient.SendAsync(request, cancellationToken).ConfigureAwait(false);
        await EnsureSuccessAsync(response, cancellationToken).ConfigureAwait(false);
    }

    private static HttpRequestMessage CreateRequest(
        HttpMethod method,
        Uri baseAddress,
        string relativePath,
        string? token)
    {
        var request = new HttpRequestMessage(method, new Uri(baseAddress, relativePath));
        ConfigureAuthenticatedRequest(request, token);
        return request;
    }

    private static void ConfigureAuthenticatedRequest(HttpRequestMessage request, string? token)
    {
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        request.Headers.UserAgent.ParseAdd($"NEX-Printer/{ClientConstants.ClientVersion}");

        if (!string.IsNullOrWhiteSpace(token))
        {
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
        }
    }

    private static void EnsureSameOrigin(Uri serverUrl, Uri payloadUrl)
    {
        if (!string.Equals(serverUrl.Scheme, payloadUrl.Scheme, StringComparison.OrdinalIgnoreCase)
            || !string.Equals(serverUrl.Host, payloadUrl.Host, StringComparison.OrdinalIgnoreCase)
            || serverUrl.Port != payloadUrl.Port)
        {
            throw new PrintJobException(
                "payload_origin_mismatch",
                "Serwer wskazał plik PDF poza adresem skonfigurowanego NEX-OMS.");
        }
    }

    private static async Task EnsureSuccessAsync(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        if (response.IsSuccessStatusCode)
        {
            return;
        }

        ErrorEnvelope? envelope = null;
        try
        {
            envelope = await response.Content.ReadFromJsonAsync<ErrorEnvelope>(JsonOptions, cancellationToken)
                .ConfigureAwait(false);
        }
        catch (Exception exception) when (exception is JsonException or NotSupportedException)
        {
            // A generic message below is safer than including an arbitrary server response.
        }

        var message = envelope?.Error.Message
            ?? $"Serwer NEX-OMS odrzucił żądanie ({(int)response.StatusCode}).";

        if (response.StatusCode == HttpStatusCode.Unauthorized)
        {
            throw new AgentUnauthorizedException(message);
        }

        throw new NexPrinterApiException(response.StatusCode, envelope?.Error.Code, message);
    }

    private sealed record PairingRequest(
        [property: JsonPropertyName("code")] string Code,
        [property: JsonPropertyName("name")] string Name,
        [property: JsonPropertyName("platform")] string Platform,
        [property: JsonPropertyName("client_version")] string ClientVersion);

    private sealed record HeartbeatRequest(
        [property: JsonPropertyName("client_version")] string ClientVersion);

    private sealed record PrinterSynchronizationRequest(
        [property: JsonPropertyName("printers")] IReadOnlyCollection<PrinterPayload> Printers);

    private sealed record PrinterPayload(
        [property: JsonPropertyName("system_name")] string SystemName,
        [property: JsonPropertyName("display_name")] string DisplayName,
        [property: JsonPropertyName("is_default")] bool IsDefault,
        [property: JsonPropertyName("capabilities")] PrinterCapabilities Capabilities);

    private sealed record PrinterCapabilities(
        [property: JsonPropertyName("formats")] IReadOnlyCollection<string> Formats);

    private sealed record PairingResponse(
        [property: JsonPropertyName("agent")] PairingAgent Agent,
        [property: JsonPropertyName("token")] string Token);

    private sealed record PairingAgent(
        [property: JsonPropertyName("id")] string Id,
        [property: JsonPropertyName("name")] string Name);

    private sealed record ClaimResponse(
        [property: JsonPropertyName("job")] ClaimJob Job,
        [property: JsonPropertyName("lease_token")] string LeaseToken);

    private sealed record ClaimJob(
        [property: JsonPropertyName("id")] string Id,
        [property: JsonPropertyName("printer")] ClaimPrinter Printer,
        [property: JsonPropertyName("document_type")] string DocumentType,
        [property: JsonPropertyName("document_number")] string DocumentNumber,
        [property: JsonPropertyName("filename")] string Filename,
        [property: JsonPropertyName("content_type")] string ContentType,
        [property: JsonPropertyName("size")] long Size,
        [property: JsonPropertyName("sha256")] string Sha256,
        [property: JsonPropertyName("copies")] int Copies,
        [property: JsonPropertyName("payload_url")] string PayloadUrl);

    private sealed record ClaimPrinter(
        [property: JsonPropertyName("system_name")] string SystemName,
        [property: JsonPropertyName("display_name")] string DisplayName);

    private sealed record CompletionRequest(
        [property: JsonPropertyName("status")] string Status,
        [property: JsonPropertyName("error_code")] string? ErrorCode,
        [property: JsonPropertyName("error_message")] string? ErrorMessage);

    private sealed record ErrorEnvelope(
        [property: JsonPropertyName("error")] ErrorPayload Error);

    private sealed record ErrorPayload(
        [property: JsonPropertyName("code")] string? Code,
        [property: JsonPropertyName("message")] string Message);
}
