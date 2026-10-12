using NexPrinter.Core.Configuration;

namespace NexPrinter.Core.Api;

public interface INexPrinterApiClient
{
    Task<PairingResult> PairAsync(
        Uri serverUrl,
        string pairingCode,
        string computerName,
        CancellationToken cancellationToken);

    Task SendHeartbeatAsync(ClientCredentials credentials, CancellationToken cancellationToken);

    Task SynchronizePrintersAsync(
        ClientCredentials credentials,
        IReadOnlyCollection<PrinterDescriptor> printers,
        CancellationToken cancellationToken);

    Task<ClaimedPrintJob?> ClaimPrintJobAsync(
        ClientCredentials credentials,
        CancellationToken cancellationToken);

    Task<byte[]> DownloadPrintJobPayloadAsync(
        ClientCredentials credentials,
        ClaimedPrintJob claimedJob,
        CancellationToken cancellationToken);

    Task CompletePrintJobAsync(
        ClientCredentials credentials,
        ClaimedPrintJob claimedJob,
        PrintJobCompletion completion,
        CancellationToken cancellationToken);
}

