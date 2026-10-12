using NexPrinter.Core.Api;
using NexPrinter.Core.Configuration;
using NexPrinter.Core.Logging;

namespace NexPrinter.Core.Printing;

public sealed class PrintJobWorker : IAsyncDisposable
{
    private readonly INexPrinterApiClient _apiClient;
    private readonly IClientCredentialStore _credentialStore;
    private readonly IPrinterCatalog _printerCatalog;
    private readonly IPrintJobJournalStore _journalStore;
    private readonly IPrintHistoryStore _historyStore;
    private readonly IPdfPrinter _pdfPrinter;
    private readonly IAppLogger _logger;
    private readonly PrintJobWorkerOptions _options;
    private readonly SemaphoreSlim _lifecycleGate = new(1, 1);
    private readonly SemaphoreSlim _operationGate = new(1, 1);
    private CancellationTokenSource? _loopCancellation;
    private Task? _loopTask;

    public PrintJobWorker(
        INexPrinterApiClient apiClient,
        IClientCredentialStore credentialStore,
        IPrinterCatalog printerCatalog,
        IPrintJobJournalStore journalStore,
        IPrintHistoryStore historyStore,
        IPdfPrinter pdfPrinter,
        IAppLogger logger,
        PrintJobWorkerOptions? options = null)
    {
        _apiClient = apiClient ?? throw new ArgumentNullException(nameof(apiClient));
        _credentialStore = credentialStore ?? throw new ArgumentNullException(nameof(credentialStore));
        _printerCatalog = printerCatalog ?? throw new ArgumentNullException(nameof(printerCatalog));
        _journalStore = journalStore ?? throw new ArgumentNullException(nameof(journalStore));
        _historyStore = historyStore ?? throw new ArgumentNullException(nameof(historyStore));
        _pdfPrinter = pdfPrinter ?? throw new ArgumentNullException(nameof(pdfPrinter));
        _logger = logger ?? throw new ArgumentNullException(nameof(logger));
        _options = options ?? new PrintJobWorkerOptions();

        if (_options.PollInterval <= TimeSpan.Zero)
        {
            throw new ArgumentOutOfRangeException(nameof(options), "Interwał odpytywania musi być dodatni.");
        }
    }

    public event EventHandler<PrintJobWorkerStatus>? StatusChanged;

    public PrintJobWorkerStatus CurrentStatus { get; private set; } = new(
        PrintJobWorkerState.Waiting,
        "Oczekiwanie",
        "Połącz program z NEX-OMS, aby odbierać zadania wydruku.",
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

    public async Task ProcessOnceAsync(CancellationToken cancellationToken = default)
    {
        await _operationGate.WaitAsync(cancellationToken).ConfigureAwait(false);
        try
        {
            var credentials = _credentialStore.Load();
            if (credentials is null)
            {
                Publish(new PrintJobWorkerStatus(
                    PrintJobWorkerState.Waiting,
                    "Oczekiwanie",
                    "Połącz program z NEX-OMS, aby odbierać zadania wydruku.",
                    DateTimeOffset.Now));
                return;
            }

            var journal = _journalStore.Load();
            if (journal is null)
            {
                var claimed = await _apiClient.ClaimPrintJobAsync(credentials, cancellationToken)
                    .ConfigureAwait(false);
                if (claimed is null)
                {
                    PublishIdleUnlessLastResult();
                    return;
                }

                journal = new PrintJobJournal(
                    claimed,
                    PrintJobJournalState.Claimed,
                    credentials.ServerUrl.ToString(),
                    credentials.AgentId,
                    DateTimeOffset.UtcNow);
                _journalStore.Save(journal);
                _logger.Information(
                    $"Pobrano zadanie {claimed.Job.Id} dla dokumentu {claimed.Job.DocumentNumber}.");
            }

            EnsureJournalContext(journal, credentials);
            await AdvanceAsync(journal, credentials, cancellationToken).ConfigureAwait(false);
        }
        finally
        {
            _operationGate.Release();
        }
    }

    public async ValueTask DisposeAsync()
    {
        await StopAsync().ConfigureAwait(false);
        _lifecycleGate.Dispose();
        _operationGate.Dispose();
    }

    private async Task RunAsync(CancellationToken cancellationToken)
    {
        while (!cancellationToken.IsCancellationRequested)
        {
            try
            {
                await ProcessOnceAsync(cancellationToken).ConfigureAwait(false);
            }
            catch (AgentUnauthorizedException)
            {
                _credentialStore.Clear();
                _logger.Warning("Serwer odrzucił połączenie podczas odbierania zadania wydruku.");
                Publish(new PrintJobWorkerStatus(
                    PrintJobWorkerState.Waiting,
                    "Połącz ponownie",
                    "Połączenie z NEX-OMS zostało odłączone.",
                    DateTimeOffset.Now));
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception exception) when (exception is
                HttpRequestException or
                TaskCanceledException or
                IOException or
                NexPrinterApiException)
            {
                _logger.Error("Nie udało się obsłużyć kolejki wydruku.", exception);
                Publish(new PrintJobWorkerStatus(
                    PrintJobWorkerState.Offline,
                    "Kolejka niedostępna",
                    "NEX Printer spróbuje ponownie za chwilę.",
                    DateTimeOffset.Now));
            }
            catch (PrintJobException exception)
            {
                _logger.Error($"Zatrzymano obsługę zadania: {exception.ErrorCode}.", exception);
                Publish(new PrintJobWorkerStatus(
                    PrintJobWorkerState.Failed,
                    "Wymagana uwaga",
                    exception.Message,
                    DateTimeOffset.Now));
            }
            catch (Exception exception)
            {
                _logger.Error("Nieoczekiwany błąd pracownika wydruku.", exception);
                Publish(new PrintJobWorkerStatus(
                    PrintJobWorkerState.Failed,
                    "Błąd NEX Printer",
                    "Nie udało się obsłużyć zadania wydruku. Szczegóły zapisano w dzienniku.",
                    DateTimeOffset.Now));
            }

            await Task.Delay(_options.PollInterval, cancellationToken).ConfigureAwait(false);
        }
    }

    private async Task AdvanceAsync(
        PrintJobJournal journal,
        ClientCredentials credentials,
        CancellationToken cancellationToken)
    {
        if (journal.State == PrintJobJournalState.Reported)
        {
            _journalStore.Clear(journal);
            return;
        }

        if (journal.State == PrintJobJournalState.Printing)
        {
            journal = MarkFailed(
                journal,
                "print_result_unknown",
                "Program został przerwany podczas drukowania. Zadanie nie zostanie wydrukowane ponownie automatycznie.");
        }

        if (journal.State == PrintJobJournalState.Claimed)
        {
            PublishForJob(
                PrintJobWorkerState.Downloading,
                "Pobieranie PDF",
                "Pobieranie i sprawdzanie dokumentu...",
                journal);

            try
            {
                var contents = await _apiClient.DownloadPrintJobPayloadAsync(
                        credentials,
                        journal.ClaimedJob,
                        cancellationToken)
                    .ConfigureAwait(false);
                _journalStore.SavePayload(journal.ClaimedJob.Job.Id, contents);
                journal = journal with
                {
                    State = PrintJobJournalState.Downloaded,
                    UpdatedAtUtc = DateTimeOffset.UtcNow,
                };
                _journalStore.Save(journal);
            }
            catch (PrintJobException exception)
            {
                journal = MarkFailed(journal, exception.ErrorCode, exception.Message);
            }
        }

        if (journal.State == PrintJobJournalState.Downloaded)
        {
            try
            {
                if (!_journalStore.PayloadExists(journal.ClaimedJob.Job.Id))
                {
                    throw new PrintJobException(
                        "local_payload_missing",
                        "Pobrany dokument PDF nie jest dostępny lokalnie.");
                }

                var contents = _journalStore.ReadPayload(journal.ClaimedJob.Job.Id);
                PrintPayloadValidator.ValidateContents(journal.ClaimedJob.Job, contents);
            }
            catch (PrintJobException exception)
            {
                journal = MarkFailed(journal, exception.ErrorCode, exception.Message);
            }

            if (journal.State == PrintJobJournalState.Downloaded)
            {
                var printerExists = _printerCatalog.GetInstalledPrinters().Any(printer =>
                    string.Equals(
                        printer.SystemName,
                        journal.ClaimedJob.Job.PrinterSystemName,
                        StringComparison.Ordinal));
                if (!printerExists)
                {
                    journal = MarkFailed(
                        journal,
                        "printer_unavailable",
                        $"Drukarka „{journal.ClaimedJob.Job.PrinterDisplayName}” nie jest dostępna na tym komputerze.");
                }
            }

            if (journal.State == PrintJobJournalState.Downloaded)
            {
                PublishForJob(
                    PrintJobWorkerState.Printing,
                    "Drukowanie",
                    "Przekazywanie dokumentu do drukarki Windows...",
                    journal);
                journal = journal with
                {
                    State = PrintJobJournalState.Printing,
                    UpdatedAtUtc = DateTimeOffset.UtcNow,
                };
                _journalStore.Save(journal);

                PdfPrintResult printResult;
                try
                {
                    printResult = await _pdfPrinter.PrintAsync(
                            _journalStore.GetPayloadPath(journal.ClaimedJob.Job.Id),
                            journal.ClaimedJob.Job.PrinterSystemName,
                            journal.ClaimedJob.Job.Copies,
                            cancellationToken)
                        .ConfigureAwait(false);
                }
                catch (Exception exception)
                {
                    _logger.Error("Silnik PDF zakończył drukowanie błędem.", exception);
                    printResult = PdfPrintResult.Failure(
                        "print_failed",
                        "Nie udało się przekazać dokumentu do drukarki Windows.");
                }

                journal = printResult.Succeeded
                    ? journal with
                    {
                        State = PrintJobJournalState.Spooled,
                        Completion = new PrintJobCompletion(PrintJobCompletionStatus.Spooled),
                        UpdatedAtUtc = DateTimeOffset.UtcNow,
                    }
                    : MarkFailed(
                        journal,
                        printResult.ErrorCode ?? "print_failed",
                        printResult.ErrorMessage ?? "Nie udało się wydrukować dokumentu.");
                _journalStore.Save(journal);
            }
        }

        if (journal.State is PrintJobJournalState.Spooled or PrintJobJournalState.Failed)
        {
            await ReportAsync(journal, credentials, cancellationToken).ConfigureAwait(false);
        }
    }

    private async Task ReportAsync(
        PrintJobJournal journal,
        ClientCredentials credentials,
        CancellationToken cancellationToken)
    {
        PublishForJob(
            PrintJobWorkerState.Reporting,
            "Zapisywanie wyniku",
            "Przekazywanie wyniku do NEX-OMS...",
            journal);

        try
        {
            await _apiClient.CompletePrintJobAsync(
                    credentials,
                    journal.ClaimedJob,
                    journal.Completion ?? throw new PrintJobException(
                        "missing_print_result",
                        "Brakuje wyniku lokalnego zadania wydruku."),
                    cancellationToken)
                .ConfigureAwait(false);
        }
        catch (NexPrinterApiException exception) when (
            string.Equals(exception.ErrorCode, "invalid_job_lease", StringComparison.Ordinal))
        {
            var renewed = await _apiClient.ClaimPrintJobAsync(credentials, cancellationToken)
                .ConfigureAwait(false);
            if (renewed is null
                || !string.Equals(
                    renewed.Job.Id,
                    journal.ClaimedJob.Job.Id,
                    StringComparison.Ordinal))
            {
                throw new PrintJobException(
                    "lease_recovery_pending",
                    "Nie udało się jeszcze odnowić dzierżawy zakończonego zadania. Program spróbuje ponownie.");
            }

            journal = journal with
            {
                ClaimedJob = renewed,
                UpdatedAtUtc = DateTimeOffset.UtcNow,
            };
            _journalStore.Save(journal);
            await _apiClient.CompletePrintJobAsync(
                    credentials,
                    journal.ClaimedJob,
                    journal.Completion!,
                    cancellationToken)
                .ConfigureAwait(false);
        }

        SaveHistory(journal);

        journal = journal with
        {
            State = PrintJobJournalState.Reported,
            UpdatedAtUtc = DateTimeOffset.UtcNow,
        };
        _journalStore.Save(journal);

        if (journal.Completion?.Status == PrintJobCompletionStatus.Spooled)
        {
            _logger.Information(
                $"Przekazano dokument {journal.ClaimedJob.Job.DocumentNumber} do drukarki.");
            PublishForJob(
                PrintJobWorkerState.Succeeded,
                "Wydruk przekazany",
                "Dokument został przekazany do kolejki drukarki Windows.",
                journal);
        }
        else
        {
            _logger.Warning(
                $"Zadanie {journal.ClaimedJob.Job.Id} zakończono błędem: {journal.Completion?.ErrorCode}.");
            PublishForJob(
                PrintJobWorkerState.Failed,
                "Wydruk nieudany",
                journal.Completion?.ErrorMessage ?? "Nie udało się wydrukować dokumentu.",
                journal);
        }

        _journalStore.Clear(journal);
    }

    private void SaveHistory(PrintJobJournal journal)
    {
        var succeeded = journal.Completion?.Status == PrintJobCompletionStatus.Spooled;

        try
        {
            _historyStore.Save(new PrintHistoryEntry(
                journal.ClaimedJob.Job.Id,
                journal.ClaimedJob.Job.DocumentNumber,
                journal.ClaimedJob.Job.PrinterDisplayName,
                succeeded ? PrintHistoryResult.Succeeded : PrintHistoryResult.Failed,
                DateTimeOffset.Now,
                succeeded
                    ? "Dokument został przekazany do kolejki drukarki Windows."
                    : journal.Completion?.ErrorMessage ?? "Nie udało się wydrukować dokumentu.",
                journal.Completion?.ErrorCode));
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            _logger.Error("Nie udało się zapisać lokalnej historii wydruku.", exception);
        }
    }

    private PrintJobJournal MarkFailed(
        PrintJobJournal journal,
        string errorCode,
        string errorMessage)
    {
        var failed = journal with
        {
            State = PrintJobJournalState.Failed,
            Completion = new PrintJobCompletion(
                PrintJobCompletionStatus.Failed,
                errorCode,
                Limit(errorMessage, 1000)),
            UpdatedAtUtc = DateTimeOffset.UtcNow,
        };
        _journalStore.Save(failed);
        return failed;
    }

    private static void EnsureJournalContext(
        PrintJobJournal journal,
        ClientCredentials credentials)
    {
        if (!string.Equals(journal.AgentId, credentials.AgentId, StringComparison.Ordinal)
            || !string.Equals(
                journal.ServerUrl.TrimEnd('/'),
                credentials.ServerUrl.ToString().TrimEnd('/'),
                StringComparison.OrdinalIgnoreCase))
        {
            throw new PrintJobException(
                "recovery_context_mismatch",
                "Niedokończone zadanie pochodzi z innego połączenia NEX-OMS. Nie zostanie wydrukowane automatycznie.");
        }
    }

    private void PublishIdleUnlessLastResult()
    {
        if (CurrentStatus.State is PrintJobWorkerState.Succeeded or PrintJobWorkerState.Failed)
        {
            return;
        }

        Publish(new PrintJobWorkerStatus(
            PrintJobWorkerState.Idle,
            "Gotowy",
            "Brak oczekujących zadań. NEX Printer sprawdza kolejkę co 2 sekundy.",
            DateTimeOffset.Now));
    }

    private void PublishForJob(
        PrintJobWorkerState state,
        string title,
        string message,
        PrintJobJournal journal)
    {
        Publish(new PrintJobWorkerStatus(
            state,
            title,
            message,
            DateTimeOffset.Now,
            journal.ClaimedJob.Job.DocumentNumber,
            journal.ClaimedJob.Job.PrinterDisplayName));
    }

    private void Publish(PrintJobWorkerStatus status)
    {
        if (CurrentStatus.State == status.State
            && string.Equals(CurrentStatus.Title, status.Title, StringComparison.Ordinal)
            && string.Equals(CurrentStatus.Message, status.Message, StringComparison.Ordinal)
            && string.Equals(CurrentStatus.DocumentNumber, status.DocumentNumber, StringComparison.Ordinal)
            && string.Equals(CurrentStatus.PrinterName, status.PrinterName, StringComparison.Ordinal))
        {
            return;
        }

        CurrentStatus = status;
        var handlers = StatusChanged;
        if (handlers is null)
        {
            return;
        }

        foreach (EventHandler<PrintJobWorkerStatus> handler in handlers.GetInvocationList())
        {
            try
            {
                handler(this, status);
            }
            catch (Exception exception)
            {
                _logger.Error("Obsługa zmiany stanu wydruku zakończyła się błędem.", exception);
            }
        }
    }

    private static string Limit(string value, int maxLength) =>
        value.Length <= maxLength ? value : value[..maxLength];
}
