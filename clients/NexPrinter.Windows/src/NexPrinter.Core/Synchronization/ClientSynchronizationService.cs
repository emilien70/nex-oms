using NexPrinter.Core.Api;
using NexPrinter.Core.Configuration;
using NexPrinter.Core.Logging;
using NexPrinter.Core.Printing;

namespace NexPrinter.Core.Synchronization;

public sealed class ClientSynchronizationService : IAsyncDisposable
{
    private readonly INexPrinterApiClient _apiClient;
    private readonly IClientCredentialStore _credentialStore;
    private readonly IPrinterCatalog _printerCatalog;
    private readonly IAppLogger _logger;
    private readonly SynchronizationOptions _options;
    private readonly SemaphoreSlim _lifecycleGate = new(1, 1);
    private readonly SemaphoreSlim _operationGate = new(1, 1);
    private CancellationTokenSource? _loopCancellation;
    private Task? _loopTask;
    private DateTimeOffset _nextPrinterSynchronizationUtc = DateTimeOffset.MinValue;
    private DateTimeOffset? _lastSuccessfulConnectionUtc;
    private int _printerCount;

    public ClientSynchronizationService(
        INexPrinterApiClient apiClient,
        IClientCredentialStore credentialStore,
        IPrinterCatalog printerCatalog,
        IAppLogger logger,
        SynchronizationOptions? options = null)
    {
        _apiClient = apiClient ?? throw new ArgumentNullException(nameof(apiClient));
        _credentialStore = credentialStore ?? throw new ArgumentNullException(nameof(credentialStore));
        _printerCatalog = printerCatalog ?? throw new ArgumentNullException(nameof(printerCatalog));
        _logger = logger ?? throw new ArgumentNullException(nameof(logger));
        _options = options ?? new SynchronizationOptions();

        if (_options.RetryDelays.Count == 0)
        {
            throw new ArgumentException("Należy skonfigurować co najmniej jedno opóźnienie ponowienia.", nameof(options));
        }
    }

    public event EventHandler<ConnectionStatus>? StatusChanged;

    public ConnectionStatus CurrentStatus { get; private set; } = new(
        ConnectionState.Unpaired,
        "Program nie jest połączony z NEX-OMS.",
        DateTimeOffset.Now);

    public async Task StartAsync()
    {
        await _lifecycleGate.WaitAsync().ConfigureAwait(false);
        try
        {
            if (_loopTask is { IsCompleted: false })
            {
                return;
            }

            _loopCancellation?.Dispose();
            _loopCancellation = new CancellationTokenSource();
            _loopTask = RunAsync(_loopCancellation.Token);
        }
        finally
        {
            _lifecycleGate.Release();
        }
    }

    public async Task RestartAsync()
    {
        await StopAsync().ConfigureAwait(false);
        _nextPrinterSynchronizationUtc = DateTimeOffset.MinValue;
        await StartAsync().ConfigureAwait(false);
    }

    public async Task StopAsync()
    {
        Task? taskToWait;
        CancellationTokenSource? cancellation;

        await _lifecycleGate.WaitAsync().ConfigureAwait(false);
        try
        {
            taskToWait = _loopTask;
            cancellation = _loopCancellation;
            _loopTask = null;
            _loopCancellation = null;
            cancellation?.Cancel();
        }
        finally
        {
            _lifecycleGate.Release();
        }

        if (taskToWait is not null)
        {
            try
            {
                await taskToWait.ConfigureAwait(false);
            }
            catch (OperationCanceledException) when (cancellation?.IsCancellationRequested == true)
            {
                // Expected while stopping the background loop.
            }
        }

        cancellation?.Dispose();
    }

    public async Task<bool> SynchronizeNowAsync(CancellationToken cancellationToken = default)
    {
        return await ExecuteCycleAsync(forcePrinterSynchronization: true, cancellationToken)
            .ConfigureAwait(false);
    }

    public async ValueTask DisposeAsync()
    {
        await StopAsync().ConfigureAwait(false);
        _lifecycleGate.Dispose();
        _operationGate.Dispose();
    }

    private async Task RunAsync(CancellationToken cancellationToken)
    {
        var failedAttempts = 0;

        while (!cancellationToken.IsCancellationRequested)
        {
            var credentials = _credentialStore.Load();
            if (credentials is null)
            {
                Publish(new ConnectionStatus(
                    ConnectionState.Unpaired,
                    "Program nie jest połączony z NEX-OMS.",
                    DateTimeOffset.Now));
                return;
            }

            var succeeded = await ExecuteCycleAsync(
                    forcePrinterSynchronization: false,
                    cancellationToken)
                .ConfigureAwait(false);

            if (_credentialStore.Load() is null)
            {
                return;
            }

            TimeSpan delay;
            if (succeeded)
            {
                failedAttempts = 0;
                delay = _options.HeartbeatInterval;
            }
            else
            {
                delay = _options.RetryDelays[Math.Min(failedAttempts, _options.RetryDelays.Count - 1)];
                failedAttempts++;
                Publish(CurrentStatus with { RetryAfter = delay });
            }

            await Task.Delay(delay, cancellationToken).ConfigureAwait(false);
        }
    }

    private async Task<bool> ExecuteCycleAsync(
        bool forcePrinterSynchronization,
        CancellationToken cancellationToken)
    {
        await _operationGate.WaitAsync(cancellationToken).ConfigureAwait(false);
        try
        {
            var credentials = _credentialStore.Load();
            if (credentials is null)
            {
                Publish(new ConnectionStatus(
                    ConnectionState.Unpaired,
                    "Program nie jest połączony z NEX-OMS.",
                    DateTimeOffset.Now));
                return false;
            }

            Publish(CurrentStatus with
            {
                State = ConnectionState.Connecting,
                Message = "Łączenie z NEX-OMS...",
                ChangedAt = DateTimeOffset.Now,
                RetryAfter = null,
            });

            await _apiClient.SendHeartbeatAsync(credentials, cancellationToken).ConfigureAwait(false);

            if (forcePrinterSynchronization || DateTimeOffset.UtcNow >= _nextPrinterSynchronizationUtc)
            {
                var printers = _printerCatalog.GetInstalledPrinters();
                await _apiClient.SynchronizePrintersAsync(credentials, printers, cancellationToken)
                    .ConfigureAwait(false);
                _printerCount = printers.Count;
                _nextPrinterSynchronizationUtc = DateTimeOffset.UtcNow + _options.PrinterSynchronizationInterval;
                _logger.Information($"Zsynchronizowano listę drukarek: {_printerCount}.");
            }

            _lastSuccessfulConnectionUtc = DateTimeOffset.Now;
            Publish(new ConnectionStatus(
                ConnectionState.Connected,
                "Połączono z NEX-OMS.",
                DateTimeOffset.Now,
                _lastSuccessfulConnectionUtc,
                _printerCount));
            return true;
        }
        catch (AgentUnauthorizedException)
        {
            _logger.Warning("Serwer odrzucił zapisane połączenie. Wymagane jest ponowne parowanie.");
            _credentialStore.Clear();
            Publish(new ConnectionStatus(
                ConnectionState.PairingRequired,
                "Połączenie zostało odłączone w NEX-OMS. Połącz program ponownie.",
                DateTimeOffset.Now,
                _lastSuccessfulConnectionUtc,
                _printerCount));
            return false;
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception exception) when (exception is HttpRequestException or TaskCanceledException or NexPrinterApiException or IOException)
        {
            _logger.Error("Synchronizacja z NEX-OMS nie powiodła się.", exception);
            Publish(new ConnectionStatus(
                ConnectionState.Offline,
                UserMessage(exception),
                DateTimeOffset.Now,
                _lastSuccessfulConnectionUtc,
                _printerCount));
            return false;
        }
        finally
        {
            _operationGate.Release();
        }
    }

    private static string UserMessage(Exception exception) => exception switch
    {
        NexPrinterApiException apiException => apiException.Message,
        TaskCanceledException => "Serwer NEX-OMS nie odpowiedział w wymaganym czasie.",
        _ => "Nie można połączyć się z NEX-OMS. Program spróbuje ponownie.",
    };

    private void Publish(ConnectionStatus status)
    {
        CurrentStatus = status;

        var handlers = StatusChanged;
        if (handlers is null)
        {
            return;
        }

        foreach (EventHandler<ConnectionStatus> handler in handlers.GetInvocationList())
        {
            try
            {
                handler(this, status);
            }
            catch (Exception exception)
            {
                _logger.Error("Obsługa zmiany statusu klienta zakończyła się błędem.", exception);
            }
        }
    }
}
