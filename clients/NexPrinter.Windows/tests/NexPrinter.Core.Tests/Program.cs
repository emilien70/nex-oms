using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using NexPrinter.Core.Api;
using NexPrinter.Core.Configuration;
using NexPrinter.Core.Logging;
using NexPrinter.Core.Printing;
using NexPrinter.Core.Synchronization;

namespace NexPrinter.Core.Tests;

internal static class Program
{
    private static readonly (string Name, Func<Task> Test)[] Tests =
    [
        ("HTTP jest dozwolone dla lokalnego NEX-OMS", LocalHttpIsAllowed),
        ("HTTP jest blokowane dla serwera zewnętrznego", ExternalHttpIsRejected),
        ("Parowanie używa poprawnego endpointu i kontraktu", PairingUsesExpectedContract),
        ("Heartbeat przekazuje token jako Bearer", HeartbeatUsesBearerToken),
        ("Synchronizacja przekazuje drukarki i format PDF", PrinterSynchronizationUsesExpectedContract),
        ("Pusta kolejka zwraca brak zadania", EmptyQueueReturnsNoJob),
        ("Odebranie zadania używa poprawnego kontraktu", ClaimUsesExpectedContract),
        ("Adres PDF spoza NEX-OMS jest blokowany", ForeignPayloadOriginIsRejected),
        ("Nieprawidłowa suma PDF jest blokowana", InvalidPayloadHashIsRejected),
        ("Nieprawidłowy typ pliku jest blokowany", InvalidPayloadContentTypeIsRejected),
        ("Błąd 401 wymaga ponownego parowania", UnauthorizedResponseRequiresPairing),
        ("Token nie jest zapisywany jawnym tekstem", TokenIsNotStoredAsPlainText),
        ("Windows DPAPI szyfruje i odszyfrowuje token", WindowsDpapiProtectsToken),
        ("Dane programu używają katalogu NEX Printer", ClientDataUsesFlatApplicationDirectory),
        ("Dane ze starego katalogu są bezpiecznie importowane", LegacyClientDataIsImportedWithoutOverwrite),
        ("Parametr background uruchamia program w tle", BackgroundLaunchOptionIsParsed),
        ("Autostart zapisuje bieżącą ścieżkę programu", AutostartUsesCurrentExecutablePath),
        ("Autostart naprawia ścieżkę po aktualizacji", AutostartRepairsExecutablePath),
        ("Preferencja instalatora jest stosowana tylko raz", InstallerAutostartPreferenceIsAppliedOnce),
        ("Ustawienia programu są trwałe i odporne na uszkodzony plik", ApplicationSettingsArePersistent),
        ("Dziennik zadania nie zapisuje dzierżawy jawnym tekstem", PrintJournalIsEncrypted),
        ("Historia wydruków jest trwała i nie dubluje zadań", PrintHistoryIsPersistentAndDeduplicated),
        ("Koordynator wysyła heartbeat i listę drukarek", CoordinatorSynchronizesClient),
        ("Koordynator usuwa odłączone poświadczenie", CoordinatorClearsRevokedCredentials),
        ("Pracownik drukuje i raportuje sukces", WorkerPrintsAndReportsSuccess),
        ("Brak wskazanej drukarki nie używa zamiennika", MissingPrinterDoesNotFallback),
        ("Stan spooled po restarcie nie drukuje ponownie", SpooledRecoveryDoesNotReprint),
        ("Przerwanie podczas drukowania nie drukuje ponownie", PrintingRecoveryIsMarkedUncertain),
        ("Nieudany raport jest ponawiany bez ponownego druku", ReportRetryDoesNotReprint),
        ("Wygasła dzierżawa wyniku jest odnawiana bez ponownego druku", ExpiredResultLeaseIsRenewed),
    ];

    public static async Task<int> Main()
    {
        var failed = 0;

        foreach (var (name, test) in Tests)
        {
            try
            {
                await test();
                Console.WriteLine($"PASS: {name}");
            }
            catch (Exception exception)
            {
                failed++;
                Console.Error.WriteLine($"FAIL: {name}");
                Console.Error.WriteLine(exception);
            }
        }

        Console.WriteLine($"Wynik: {Tests.Length - failed}/{Tests.Length} testów zakończonych powodzeniem.");
        return failed == 0 ? 0 : 1;
    }

    private static Task LocalHttpIsAllowed()
    {
        var uri = ServerAddressPolicy.Normalize("http://127.0.0.1:8000");
        AssertEqual("http://127.0.0.1:8000/", uri.ToString());
        return Task.CompletedTask;
    }

    private static Task ExternalHttpIsRejected()
    {
        AssertThrows<ArgumentException>(() => ServerAddressPolicy.Normalize("http://example.com"));
        return Task.CompletedTask;
    }

    private static async Task PairingUsesExpectedContract()
    {
        var handler = new StubHttpMessageHandler(async request =>
        {
            AssertEqual(HttpMethod.Post, request.Method);
            AssertEqual("http://127.0.0.1:8000/api/nex-printer/v1/pair", request.RequestUri?.ToString());
            AssertTrue(request.Headers.Authorization is null, "Parowanie nie może wysyłać starego tokenu.");

            using var body = JsonDocument.Parse(await request.Content!.ReadAsStringAsync());
            AssertEqual("ABC1234567", body.RootElement.GetProperty("code").GetString());
            AssertEqual("Komputer testowy", body.RootElement.GetProperty("name").GetString());
            AssertEqual("windows", body.RootElement.GetProperty("platform").GetString());
            AssertEqual("0.5.0", body.RootElement.GetProperty("client_version").GetString());

            return JsonResponse(HttpStatusCode.Created, """
                {"agent":{"id":"agent-1","name":"Komputer testowy"},"token":"secret-token"}
                """);
        });
        var client = new NexPrinterApiClient(new HttpClient(handler));

        var result = await client.PairAsync(
            new Uri("http://127.0.0.1:8000/"),
            "abc1234567",
            "Komputer testowy",
            CancellationToken.None);

        AssertEqual("agent-1", result.AgentId);
        AssertEqual("secret-token", result.Token);
    }

    private static async Task HeartbeatUsesBearerToken()
    {
        var handler = new StubHttpMessageHandler(async request =>
        {
            AssertEqual(HttpMethod.Post, request.Method);
            AssertEqual("http://localhost:8000/api/nex-printer/v1/heartbeat", request.RequestUri?.ToString());
            AssertEqual(new AuthenticationHeaderValue("Bearer", "token-123"), request.Headers.Authorization);
            using var body = JsonDocument.Parse(await request.Content!.ReadAsStringAsync());
            AssertEqual("0.5.0", body.RootElement.GetProperty("client_version").GetString());
            return JsonResponse(HttpStatusCode.OK, "{\"status\":\"ok\"}");
        });
        var client = new NexPrinterApiClient(new HttpClient(handler));

        await client.SendHeartbeatAsync(Credentials(), CancellationToken.None);
    }

    private static async Task PrinterSynchronizationUsesExpectedContract()
    {
        var handler = new StubHttpMessageHandler(async request =>
        {
            AssertEqual(HttpMethod.Put, request.Method);
            AssertEqual("http://localhost:8000/api/nex-printer/v1/printers", request.RequestUri?.ToString());
            using var body = JsonDocument.Parse(await request.Content!.ReadAsStringAsync());
            var printer = body.RootElement.GetProperty("printers")[0];
            AssertEqual("Drukarka PDF", printer.GetProperty("system_name").GetString());
            AssertTrue(printer.GetProperty("is_default").GetBoolean(), "Drukarka powinna być domyślna.");
            AssertEqual("pdf", printer.GetProperty("capabilities").GetProperty("formats")[0].GetString());
            return JsonResponse(HttpStatusCode.OK, "{\"status\":\"ok\"}");
        });
        var client = new NexPrinterApiClient(new HttpClient(handler));

        await client.SynchronizePrintersAsync(
            Credentials(),
            [new PrinterDescriptor("Drukarka PDF", "Drukarka PDF", true)],
            CancellationToken.None);
    }

    private static async Task EmptyQueueReturnsNoJob()
    {
        var handler = new StubHttpMessageHandler(request =>
        {
            AssertEqual(HttpMethod.Post, request.Method);
            AssertEqual(
                "http://localhost:8000/api/nex-printer/v1/jobs/claim",
                request.RequestUri?.ToString());
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.NoContent));
        });
        var client = new NexPrinterApiClient(new HttpClient(handler));

        var claimed = await client.ClaimPrintJobAsync(Credentials(), CancellationToken.None);

        AssertTrue(claimed is null, "Pusta kolejka nie powinna zwracać zadania.");
    }

    private static async Task ClaimUsesExpectedContract()
    {
        var pdf = PdfPayload();
        var handler = new StubHttpMessageHandler(request =>
        {
            AssertEqual(new AuthenticationHeaderValue("Bearer", "token-123"), request.Headers.Authorization);
            return Task.FromResult(ClaimResponse(pdf));
        });
        var client = new NexPrinterApiClient(new HttpClient(handler));

        var claimed = await client.ClaimPrintJobAsync(Credentials(), CancellationToken.None);

        AssertTrue(claimed is not null, "Serwer powinien zwrócić zadanie.");
        AssertEqual("job-1", claimed!.Job.Id);
        AssertEqual("Drukarka testowa", claimed.Job.PrinterSystemName);
        AssertEqual("FV 1/2026", claimed.Job.DocumentNumber);
        AssertEqual(1, claimed.Job.Copies);
        AssertEqual("lease-secret", claimed.LeaseToken);
    }

    private static async Task ForeignPayloadOriginIsRejected()
    {
        var pdf = PdfPayload();
        var handler = new StubHttpMessageHandler(_ => Task.FromResult(
            ClaimResponse(pdf, payloadUrl: "https://example.com/private.pdf")));
        var client = new NexPrinterApiClient(new HttpClient(handler));

        var exception = await AssertThrowsAsync<PrintJobException>(
            () => client.ClaimPrintJobAsync(Credentials(), CancellationToken.None));

        AssertEqual("payload_origin_mismatch", exception.ErrorCode);
    }

    private static async Task InvalidPayloadHashIsRejected()
    {
        var pdf = PdfPayload();
        var handler = new StubHttpMessageHandler(request =>
        {
            if (request.Method == HttpMethod.Post)
            {
                return Task.FromResult(ClaimResponse(pdf, sha256: new string('0', 64)));
            }

            var response = new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new ByteArrayContent(pdf),
            };
            response.Content.Headers.ContentType = new MediaTypeHeaderValue("application/pdf");
            return Task.FromResult(response);
        });
        var client = new NexPrinterApiClient(new HttpClient(handler));
        var claimed = await client.ClaimPrintJobAsync(Credentials(), CancellationToken.None)
            ?? throw new InvalidOperationException("Brak zadania testowego.");

        var exception = await AssertThrowsAsync<PrintJobException>(
            () => client.DownloadPrintJobPayloadAsync(
                Credentials(),
                claimed,
                CancellationToken.None));

        AssertEqual("payload_hash_mismatch", exception.ErrorCode);
    }

    private static async Task InvalidPayloadContentTypeIsRejected()
    {
        var pdf = PdfPayload();
        var handler = new StubHttpMessageHandler(request =>
        {
            if (request.Method == HttpMethod.Post)
            {
                return Task.FromResult(ClaimResponse(pdf));
            }

            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent("not-a-pdf", Encoding.UTF8, "text/plain"),
            });
        });
        var client = new NexPrinterApiClient(new HttpClient(handler));
        var claimed = await client.ClaimPrintJobAsync(Credentials(), CancellationToken.None)
            ?? throw new InvalidOperationException("Brak zadania testowego.");

        var exception = await AssertThrowsAsync<PrintJobException>(
            () => client.DownloadPrintJobPayloadAsync(
                Credentials(),
                claimed,
                CancellationToken.None));

        AssertEqual("invalid_content_type", exception.ErrorCode);
    }

    private static async Task UnauthorizedResponseRequiresPairing()
    {
        var handler = new StubHttpMessageHandler(_ => Task.FromResult(JsonResponse(
            HttpStatusCode.Unauthorized,
            "{\"error\":{\"code\":\"unauthenticated_agent\",\"message\":\"Odłączony\"}}")));
        var client = new NexPrinterApiClient(new HttpClient(handler));

        await AssertThrowsAsync<AgentUnauthorizedException>(
            () => client.SendHeartbeatAsync(Credentials(), CancellationToken.None));
    }

    private static Task TokenIsNotStoredAsPlainText()
    {
        var directory = Path.Combine(Path.GetTempPath(), "nex-printer-tests", Guid.NewGuid().ToString("N"));
        var store = new SecureClientCredentialStore(directory, new ReversingDataProtector());
        var credentials = Credentials() with { Token = "plain-secret-token" };

        try
        {
            store.Save(credentials);
            var configuration = File.ReadAllText(Path.Combine(directory, "client.json"));
            var tokenFile = Encoding.UTF8.GetString(File.ReadAllBytes(Path.Combine(directory, "token.dat")));
            AssertTrue(!configuration.Contains(credentials.Token, StringComparison.Ordinal), "Token nie może trafić do konfiguracji.");
            AssertTrue(!tokenFile.Contains(credentials.Token, StringComparison.Ordinal), "Token nie może trafić jawnie do token.dat.");

            var loaded = store.Load();
            AssertTrue(loaded is not null, "Zapisane dane powinny być możliwe do odczytania.");
            AssertEqual(credentials.Token, loaded!.Token);

            store.Clear();
            AssertTrue(store.Load() is null, "Usunięte dane nie powinny być odczytywane.");
        }
        finally
        {
            if (Directory.Exists(directory))
            {
                Directory.Delete(directory, recursive: true);
            }
        }

        return Task.CompletedTask;
    }

    private static Task WindowsDpapiProtectsToken()
    {
        var protector = new WindowsDpapiProtector();
        var plainText = Encoding.UTF8.GetBytes("nex-printer-sensitive-token");
        var cipherText = protector.Protect(plainText);

        AssertTrue(!plainText.SequenceEqual(cipherText), "DPAPI powinno zwrócić zaszyfrowane dane.");
        var decrypted = protector.Unprotect(cipherText);
        AssertTrue(plainText.SequenceEqual(decrypted), "DPAPI powinno odtworzyć pierwotny token.");

        Array.Clear(plainText);
        Array.Clear(decrypted);
        return Task.CompletedTask;
    }

    private static Task ClientDataUsesFlatApplicationDirectory()
    {
        var root = TestDirectory();

        try
        {
            var result = ClientDataDirectory.Prepare(root);

            AssertEqual(Path.Combine(root, "NEX Printer"), result.Path);
            AssertTrue(Directory.Exists(result.Path), "Katalog danych powinien zostać utworzony.");
            AssertTrue(!result.ImportedLegacyData, "Pierwsze uruchomienie nie powinno zgłaszać migracji.");
            AssertTrue(
                !Directory.Exists(Path.Combine(root, "NEX")),
                "Nowa instalacja nie może tworzyć pośredniego katalogu NEX.");
        }
        finally
        {
            DeleteTestDirectory(root);
        }

        return Task.CompletedTask;
    }

    private static Task LegacyClientDataIsImportedWithoutOverwrite()
    {
        var root = TestDirectory();
        var legacyDirectory = Path.Combine(root, "NEX", "NEX Printer");
        var applicationDirectory = Path.Combine(root, "NEX Printer");

        try
        {
            Directory.CreateDirectory(Path.Combine(legacyDirectory, "jobs"));
            Directory.CreateDirectory(applicationDirectory);
            File.WriteAllText(Path.Combine(legacyDirectory, "client.json"), "legacy-client");
            File.WriteAllText(Path.Combine(legacyDirectory, "settings.json"), "legacy-settings");
            File.WriteAllText(Path.Combine(legacyDirectory, "jobs", "active-job.dat"), "legacy-job");
            File.WriteAllText(Path.Combine(applicationDirectory, "settings.json"), "current-settings");

            var result = ClientDataDirectory.Prepare(root);

            AssertTrue(result.ImportedLegacyData, "Brakujące dane powinny zostać zaimportowane.");
            AssertEqual(
                "legacy-client",
                File.ReadAllText(Path.Combine(applicationDirectory, "client.json")));
            AssertEqual(
                "legacy-job",
                File.ReadAllText(Path.Combine(applicationDirectory, "jobs", "active-job.dat")));
            AssertEqual(
                "current-settings",
                File.ReadAllText(Path.Combine(applicationDirectory, "settings.json")));
            AssertTrue(
                File.Exists(Path.Combine(legacyDirectory, "client.json")),
                "Import nie może usuwać starej kopii danych.");
        }
        finally
        {
            DeleteTestDirectory(root);
        }

        return Task.CompletedTask;
    }

    private static Task BackgroundLaunchOptionIsParsed()
    {
        AssertTrue(
            ClientLaunchOptions.Parse(["--BACKGROUND"]).StartInBackground,
            "Parametr background powinien być rozpoznawany bez względu na wielkość liter.");
        AssertTrue(
            !ClientLaunchOptions.Parse(["--unknown"]).StartInBackground,
            "Nieznany parametr nie może uruchamiać programu w tle.");

        return Task.CompletedTask;
    }

    private static Task AutostartUsesCurrentExecutablePath()
    {
        var registry = new InMemoryAutostartRegistry();
        var executablePath = Path.Combine(TestDirectory(), "NEX Printer", "NexPrinter.exe");
        var service = new WindowsAutostartService(registry, executablePath);

        AssertTrue(!service.IsEnabled(), "Autostart powinien być początkowo wyłączony.");
        service.SetEnabled(true);
        AssertTrue(service.IsEnabled(), "Autostart powinien zostać włączony.");
        AssertEqual($"\"{Path.GetFullPath(executablePath)}\" --background", registry.Command);

        service.SetEnabled(false);
        AssertTrue(!service.IsEnabled(), "Autostart powinien zostać wyłączony.");

        return Task.CompletedTask;
    }

    private static Task AutostartRepairsExecutablePath()
    {
        var registry = new InMemoryAutostartRegistry
        {
            Command = "\"C:\\stary-katalog\\NexPrinter.exe\" --background",
        };
        var executablePath = Path.Combine(TestDirectory(), "NEX Printer", "NexPrinter.exe");
        var service = new WindowsAutostartService(registry, executablePath);

        service.RepairExecutablePathIfEnabled();

        AssertEqual($"\"{Path.GetFullPath(executablePath)}\" --background", registry.Command);

        return Task.CompletedTask;
    }

    private static Task InstallerAutostartPreferenceIsAppliedOnce()
    {
        var directory = TestDirectory();

        try
        {
            var store = new JsonClientApplicationSettingsStore(directory);
            File.WriteAllText(
                Path.Combine(directory, InstallerAutostartPreference.FileName),
                "enabled");
            var registry = new InMemoryAutostartRegistry();
            var service = new WindowsAutostartService(
                registry,
                Path.Combine(directory, "NexPrinter.exe"));

            var preference = InstallerAutostartPreference.Read(directory);
            AssertTrue(preference == true, "Instalator powinien przekazać wybór włączenia autostartu.");
            AssertTrue(
                AutostartPreferenceInitializer.InitializeIfNeeded(store, service, preference),
                "Preferencja powinna zostać zastosowana przy pierwszym uruchomieniu.");
            AssertTrue(service.IsEnabled(), "Pierwsze uruchomienie powinno włączyć autostart.");

            service.SetEnabled(false);
            AssertTrue(
                !AutostartPreferenceInitializer.InitializeIfNeeded(store, service, preference),
                "Aktualizacja nie może ponownie zastosować preferencji instalatora.");
            AssertTrue(
                !service.IsEnabled(),
                "Aktualizacja nie może włączyć autostartu wyłączonego przez użytkownika.");
        }
        finally
        {
            DeleteTestDirectory(directory);
        }

        return Task.CompletedTask;
    }

    private static Task ApplicationSettingsArePersistent()
    {
        var directory = TestDirectory();

        try
        {
            var store = new JsonClientApplicationSettingsStore(directory);
            AssertTrue(
                store.Load().ShowErrorNotifications,
                "Powiadomienia o błędach powinny być domyślnie włączone.");

            store.Save(new ClientApplicationSettings(
                ShowErrorNotifications: false,
                AutostartPreferenceInitialized: true));

            var reloaded = new JsonClientApplicationSettingsStore(directory).Load();
            AssertTrue(
                !reloaded.ShowErrorNotifications,
                "Wyłączone powiadomienia powinny zostać zachowane po ponownym uruchomieniu.");
            AssertTrue(
                reloaded.AutostartPreferenceInitialized,
                "Informacja o pierwszej konfiguracji autostartu powinna być trwała.");

            File.WriteAllText(Path.Combine(directory, "settings.json"), "{uszkodzone");
            AssertTrue(
                store.Load().ShowErrorNotifications,
                "Uszkodzony plik powinien przywrócić bezpieczne ustawienia domyślne.");
        }
        finally
        {
            DeleteTestDirectory(directory);
        }

        return Task.CompletedTask;
    }

    private static Task PrintJournalIsEncrypted()
    {
        var directory = TestDirectory();
        var store = new SecurePrintJobJournalStore(directory, new ReversingDataProtector());
        var claimed = TestJob();
        var journal = new PrintJobJournal(
            claimed,
            PrintJobJournalState.Claimed,
            Credentials().ServerUrl.ToString(),
            Credentials().AgentId,
            DateTimeOffset.UtcNow);

        try
        {
            store.Save(journal);
            var storedBytes = File.ReadAllBytes(Path.Combine(directory, "jobs", "active-job.dat"));
            var storedText = Encoding.UTF8.GetString(storedBytes);
            AssertTrue(
                !storedText.Contains(claimed.LeaseToken, StringComparison.Ordinal),
                "Token dzierżawy nie może zostać zapisany jawnym tekstem.");

            var loaded = store.Load();
            AssertTrue(loaded is not null, "Zaszyfrowany dziennik powinien być możliwy do odczytania.");
            AssertEqual(claimed.LeaseToken, loaded!.ClaimedJob.LeaseToken);
        }
        finally
        {
            DeleteTestDirectory(directory);
        }

        return Task.CompletedTask;
    }

    private static Task PrintHistoryIsPersistentAndDeduplicated()
    {
        var directory = TestDirectory();
        var firstCompletedAt = new DateTimeOffset(2026, 10, 8, 12, 0, 0, TimeSpan.Zero);
        var secondCompletedAt = firstCompletedAt.AddMinutes(5);

        try
        {
            var store = new JsonPrintHistoryStore(directory);
            store.Save(new PrintHistoryEntry(
                "job-1",
                "FV 1/2026",
                "Drukarka testowa",
                PrintHistoryResult.Succeeded,
                firstCompletedAt,
                "Pierwszy wynik."));
            store.Save(new PrintHistoryEntry(
                "job-1",
                "FV 1/2026",
                "Drukarka testowa",
                PrintHistoryResult.Failed,
                secondCompletedAt,
                "Wynik zastąpiony.",
                "print_failed"));
            store.Save(new PrintHistoryEntry(
                "job-2",
                "FV 2/2026",
                "Drukarka testowa",
                PrintHistoryResult.Succeeded,
                secondCompletedAt.AddMinutes(1),
                "Drugi dokument."));

            var reloaded = new JsonPrintHistoryStore(directory).GetRecent(10);
            AssertEqual(2, reloaded.Count);
            AssertEqual("job-2", reloaded[0].JobId);
            AssertEqual("job-1", reloaded[1].JobId);
            AssertEqual(PrintHistoryResult.Failed, reloaded[1].Result);
            AssertEqual("print_failed", reloaded[1].ErrorCode);
        }
        finally
        {
            DeleteTestDirectory(directory);
        }

        return Task.CompletedTask;
    }

    private static async Task CoordinatorSynchronizesClient()
    {
        var api = new FakeApiClient();
        var store = new InMemoryCredentialStore(Credentials());
        var service = new ClientSynchronizationService(
            api,
            store,
            new FakePrinterCatalog(),
            new NullLogger());

        try
        {
            var result = await service.SynchronizeNowAsync();
            AssertTrue(result, "Ręczna synchronizacja powinna zakończyć się powodzeniem.");
            AssertEqual(1, api.HeartbeatCalls);
            AssertEqual(1, api.PrinterSynchronizationCalls);
            AssertEqual(ConnectionState.Connected, service.CurrentStatus.State);
            AssertEqual(1, service.CurrentStatus.PrinterCount);
        }
        finally
        {
            await service.DisposeAsync();
        }
    }

    private static async Task CoordinatorClearsRevokedCredentials()
    {
        var api = new FakeApiClient { RejectHeartbeat = true };
        var store = new InMemoryCredentialStore(Credentials());
        var service = new ClientSynchronizationService(
            api,
            store,
            new FakePrinterCatalog(),
            new NullLogger());

        try
        {
            var result = await service.SynchronizeNowAsync();
            AssertTrue(!result, "Synchronizacja odłączonego klienta powinna się nie powieść.");
            AssertTrue(store.Load() is null, "Odłączony token powinien zostać usunięty lokalnie.");
            AssertEqual(ConnectionState.PairingRequired, service.CurrentStatus.State);
        }
        finally
        {
            await service.DisposeAsync();
        }
    }

    private static async Task WorkerPrintsAndReportsSuccess()
    {
        var directory = TestDirectory();
        var claimed = TestJob();
        var api = new FakeApiClient(claimed, PdfPayload());
        var printer = new FakePdfPrinter(PdfPrintResult.Success());
        var worker = CreateWorker(directory, api, printer, new FakePrinterCatalog());

        try
        {
            await worker.ProcessOnceAsync();

            AssertEqual(1, printer.Calls);
            AssertEqual("Drukarka testowa", printer.LastPrinterName);
            AssertEqual(1, api.Completions.Count);
            AssertEqual(PrintJobCompletionStatus.Spooled, api.Completions[0].Status);
            AssertEqual(PrintJobWorkerState.Succeeded, worker.CurrentStatus.State);
            var history = new JsonPrintHistoryStore(directory).GetRecent(10);
            AssertEqual(1, history.Count);
            AssertEqual("job-1", history[0].JobId);
            AssertEqual(PrintHistoryResult.Succeeded, history[0].Result);
            AssertTrue(
                new SecurePrintJobJournalStore(directory, new ReversingDataProtector()).Load() is null,
                "Zaraportowane zadanie powinno zostać usunięte z dziennika.");
        }
        finally
        {
            await worker.DisposeAsync();
            DeleteTestDirectory(directory);
        }
    }

    private static async Task MissingPrinterDoesNotFallback()
    {
        var directory = TestDirectory();
        var api = new FakeApiClient(TestJob(), PdfPayload());
        var printer = new FakePdfPrinter(PdfPrintResult.Success());
        var worker = CreateWorker(
            directory,
            api,
            printer,
            new FakePrinterCatalog("Inna drukarka"));

        try
        {
            await worker.ProcessOnceAsync();

            AssertEqual(0, printer.Calls);
            AssertEqual(1, api.Completions.Count);
            AssertEqual(PrintJobCompletionStatus.Failed, api.Completions[0].Status);
            AssertEqual("printer_unavailable", api.Completions[0].ErrorCode);
            var history = new JsonPrintHistoryStore(directory).GetRecent(10);
            AssertEqual(1, history.Count);
            AssertEqual(PrintHistoryResult.Failed, history[0].Result);
            AssertEqual("printer_unavailable", history[0].ErrorCode);
        }
        finally
        {
            await worker.DisposeAsync();
            DeleteTestDirectory(directory);
        }
    }

    private static async Task SpooledRecoveryDoesNotReprint()
    {
        var directory = TestDirectory();
        var claimed = TestJob();
        var api = new FakeApiClient(null, PdfPayload());
        var printer = new FakePdfPrinter(PdfPrintResult.Success());
        var store = new SecurePrintJobJournalStore(directory, new ReversingDataProtector());
        store.Save(new PrintJobJournal(
            claimed,
            PrintJobJournalState.Spooled,
            Credentials().ServerUrl.ToString(),
            Credentials().AgentId,
            DateTimeOffset.UtcNow,
            new PrintJobCompletion(PrintJobCompletionStatus.Spooled)));
        var worker = CreateWorker(directory, api, printer, new FakePrinterCatalog());

        try
        {
            await worker.ProcessOnceAsync();

            AssertEqual(0, printer.Calls);
            AssertEqual(1, api.Completions.Count);
            AssertEqual(PrintJobCompletionStatus.Spooled, api.Completions[0].Status);
        }
        finally
        {
            await worker.DisposeAsync();
            DeleteTestDirectory(directory);
        }
    }

    private static async Task PrintingRecoveryIsMarkedUncertain()
    {
        var directory = TestDirectory();
        var claimed = TestJob();
        var api = new FakeApiClient(null, PdfPayload());
        var printer = new FakePdfPrinter(PdfPrintResult.Success());
        var store = new SecurePrintJobJournalStore(directory, new ReversingDataProtector());
        store.Save(new PrintJobJournal(
            claimed,
            PrintJobJournalState.Printing,
            Credentials().ServerUrl.ToString(),
            Credentials().AgentId,
            DateTimeOffset.UtcNow));
        var worker = CreateWorker(directory, api, printer, new FakePrinterCatalog());

        try
        {
            await worker.ProcessOnceAsync();

            AssertEqual(0, printer.Calls);
            AssertEqual(1, api.Completions.Count);
            AssertEqual(PrintJobCompletionStatus.Failed, api.Completions[0].Status);
            AssertEqual("print_result_unknown", api.Completions[0].ErrorCode);
        }
        finally
        {
            await worker.DisposeAsync();
            DeleteTestDirectory(directory);
        }
    }

    private static async Task ReportRetryDoesNotReprint()
    {
        var directory = TestDirectory();
        var api = new FakeApiClient(TestJob(), PdfPayload())
        {
            CompletionFailuresRemaining = 1,
        };
        var printer = new FakePdfPrinter(PdfPrintResult.Success());
        var worker = CreateWorker(directory, api, printer, new FakePrinterCatalog());

        try
        {
            await AssertThrowsAsync<HttpRequestException>(() => worker.ProcessOnceAsync());
            AssertEqual(1, printer.Calls);

            await worker.ProcessOnceAsync();

            AssertEqual(1, printer.Calls);
            AssertEqual(1, api.Completions.Count);
            AssertEqual(PrintJobCompletionStatus.Spooled, api.Completions[0].Status);
        }
        finally
        {
            await worker.DisposeAsync();
            DeleteTestDirectory(directory);
        }
    }

    private static async Task ExpiredResultLeaseIsRenewed()
    {
        var directory = TestDirectory();
        var claimed = TestJob();
        var api = new FakeApiClient(claimed with { LeaseToken = "renewed-lease" }, PdfPayload())
        {
            CompletionLeaseFailuresRemaining = 1,
        };
        var printer = new FakePdfPrinter(PdfPrintResult.Success());
        var store = new SecurePrintJobJournalStore(directory, new ReversingDataProtector());
        store.Save(new PrintJobJournal(
            claimed with { LeaseToken = "expired-lease" },
            PrintJobJournalState.Spooled,
            Credentials().ServerUrl.ToString(),
            Credentials().AgentId,
            DateTimeOffset.UtcNow,
            new PrintJobCompletion(PrintJobCompletionStatus.Spooled)));
        var worker = CreateWorker(directory, api, printer, new FakePrinterCatalog());

        try
        {
            await worker.ProcessOnceAsync();

            AssertEqual(0, printer.Calls);
            AssertEqual(1, api.ClaimCalls);
            AssertEqual(1, api.Completions.Count);
            AssertEqual(PrintJobCompletionStatus.Spooled, api.Completions[0].Status);
        }
        finally
        {
            await worker.DisposeAsync();
            DeleteTestDirectory(directory);
        }
    }

    private static PrintJobWorker CreateWorker(
        string directory,
        FakeApiClient api,
        FakePdfPrinter printer,
        IPrinterCatalog printerCatalog) => new(
            api,
            new InMemoryCredentialStore(Credentials()),
            printerCatalog,
            new SecurePrintJobJournalStore(directory, new ReversingDataProtector()),
            new JsonPrintHistoryStore(directory),
            printer,
            new NullLogger(),
            new PrintJobWorkerOptions { PollInterval = TimeSpan.FromMilliseconds(10) });

    private static ClaimedPrintJob TestJob()
    {
        var pdf = PdfPayload();
        return new ClaimedPrintJob(
            new PrintJobDescriptor(
                "job-1",
                "Drukarka testowa",
                "Drukarka testowa",
                "invoice",
                "FV 1/2026",
                "FV-1-2026.pdf",
                "application/pdf",
                pdf.LongLength,
                Convert.ToHexStringLower(SHA256.HashData(pdf)),
                1,
                new Uri("http://localhost:8000/api/nex-printer/v1/jobs/job-1/payload")),
            "lease-secret");
    }

    private static byte[] PdfPayload() => Encoding.ASCII.GetBytes("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

    private static HttpResponseMessage ClaimResponse(
        byte[] pdf,
        string payloadUrl = "http://localhost:8000/api/nex-printer/v1/jobs/job-1/payload",
        string? sha256 = null)
    {
        var json = JsonSerializer.Serialize(new
        {
            job = new
            {
                id = "job-1",
                printer = new
                {
                    system_name = "Drukarka testowa",
                    display_name = "Drukarka testowa",
                },
                document_type = "invoice",
                document_number = "FV 1/2026",
                filename = "FV-1-2026.pdf",
                content_type = "application/pdf",
                size = pdf.LongLength,
                sha256 = sha256 ?? Convert.ToHexStringLower(SHA256.HashData(pdf)),
                copies = 1,
                payload_url = payloadUrl,
            },
            lease_token = "lease-secret",
        });
        return JsonResponse(HttpStatusCode.OK, json);
    }

    private static string TestDirectory() =>
        Path.Combine(Path.GetTempPath(), "nex-printer-tests", Guid.NewGuid().ToString("N"));

    private static void DeleteTestDirectory(string directory)
    {
        if (Directory.Exists(directory))
        {
            Directory.Delete(directory, recursive: true);
        }
    }

    private static ClientCredentials Credentials() => new(
        new Uri("http://localhost:8000/"),
        "agent-1",
        "Komputer testowy",
        "token-123",
        DateTimeOffset.UtcNow);

    private static HttpResponseMessage JsonResponse(HttpStatusCode status, string json) => new(status)
    {
        Content = new StringContent(json, Encoding.UTF8, "application/json"),
    };

    private static void AssertTrue(bool condition, string message)
    {
        if (!condition)
        {
            throw new InvalidOperationException(message);
        }
    }

    private static void AssertEqual<T>(T expected, T actual)
    {
        if (!EqualityComparer<T>.Default.Equals(expected, actual))
        {
            throw new InvalidOperationException($"Oczekiwano: {expected}; otrzymano: {actual}.");
        }
    }

    private static void AssertThrows<TException>(Action action)
        where TException : Exception
    {
        try
        {
            action();
        }
        catch (TException)
        {
            return;
        }

        throw new InvalidOperationException($"Oczekiwano wyjątku {typeof(TException).Name}.");
    }

    private static async Task<TException> AssertThrowsAsync<TException>(Func<Task> action)
        where TException : Exception
    {
        try
        {
            await action();
        }
        catch (TException exception)
        {
            return exception;
        }

        throw new InvalidOperationException($"Oczekiwano wyjątku {typeof(TException).Name}.");
    }

    private sealed class StubHttpMessageHandler(
        Func<HttpRequestMessage, Task<HttpResponseMessage>> responseFactory) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken) => responseFactory(request);
    }

    private sealed class ReversingDataProtector : IDataProtector
    {
        public byte[] Protect(byte[] plainText) => plainText.Reverse().Select(value => (byte)(value ^ 0x5A)).ToArray();

        public byte[] Unprotect(byte[] cipherText) => cipherText.Select(value => (byte)(value ^ 0x5A)).Reverse().ToArray();
    }

    private sealed class InMemoryAutostartRegistry : IAutostartRegistry
    {
        public string? Command { get; set; }

        public string? ReadCommand() => Command;

        public void WriteCommand(string command) => Command = command;

        public void DeleteCommand() => Command = null;
    }

    private sealed class InMemoryCredentialStore(ClientCredentials? credentials) : IClientCredentialStore
    {
        private ClientCredentials? _credentials = credentials;

        public ClientCredentials? Load() => _credentials;

        public void Save(ClientCredentials value) => _credentials = value;

        public void Clear() => _credentials = null;
    }

    private sealed class FakePrinterCatalog(string printerName = "Drukarka testowa") : IPrinterCatalog
    {
        public IReadOnlyList<PrinterDescriptor> GetInstalledPrinters() =>
            [new PrinterDescriptor(printerName, printerName, true)];
    }

    private sealed class FakeApiClient : INexPrinterApiClient
    {
        private readonly byte[] _payload;
        private ClaimedPrintJob? _jobToClaim;

        public FakeApiClient(
            ClaimedPrintJob? jobToClaim = null,
            byte[]? payload = null)
        {
            _jobToClaim = jobToClaim;
            _payload = payload ?? PdfPayload();
        }

        public int HeartbeatCalls { get; private set; }

        public int PrinterSynchronizationCalls { get; private set; }

        public int ClaimCalls { get; private set; }

        public bool RejectHeartbeat { get; init; }

        public int CompletionFailuresRemaining { get; set; }

        public int CompletionLeaseFailuresRemaining { get; set; }

        public List<PrintJobCompletion> Completions { get; } = [];

        public Task<PairingResult> PairAsync(
            Uri serverUrl,
            string pairingCode,
            string computerName,
            CancellationToken cancellationToken) => throw new NotSupportedException();

        public Task SendHeartbeatAsync(ClientCredentials credentials, CancellationToken cancellationToken)
        {
            HeartbeatCalls++;
            return RejectHeartbeat
                ? Task.FromException(new AgentUnauthorizedException("Odłączony"))
                : Task.CompletedTask;
        }

        public Task SynchronizePrintersAsync(
            ClientCredentials credentials,
            IReadOnlyCollection<PrinterDescriptor> printers,
            CancellationToken cancellationToken)
        {
            PrinterSynchronizationCalls++;
            return Task.CompletedTask;
        }

        public Task<ClaimedPrintJob?> ClaimPrintJobAsync(
            ClientCredentials credentials,
            CancellationToken cancellationToken)
        {
            ClaimCalls++;
            var claimed = _jobToClaim;
            _jobToClaim = null;
            return Task.FromResult(claimed);
        }

        public Task<byte[]> DownloadPrintJobPayloadAsync(
            ClientCredentials credentials,
            ClaimedPrintJob claimedJob,
            CancellationToken cancellationToken) => Task.FromResult(_payload.ToArray());

        public Task CompletePrintJobAsync(
            ClientCredentials credentials,
            ClaimedPrintJob claimedJob,
            PrintJobCompletion completion,
            CancellationToken cancellationToken)
        {
            if (CompletionLeaseFailuresRemaining > 0)
            {
                CompletionLeaseFailuresRemaining--;
                return Task.FromException(new NexPrinterApiException(
                    HttpStatusCode.Conflict,
                    "invalid_job_lease",
                    "Dzierżawa wygasła."));
            }

            if (CompletionFailuresRemaining > 0)
            {
                CompletionFailuresRemaining--;
                return Task.FromException(new HttpRequestException("Przejściowy błąd raportowania."));
            }

            Completions.Add(completion);
            return Task.CompletedTask;
        }
    }

    private sealed class FakePdfPrinter(PdfPrintResult result) : IPdfPrinter
    {
        public int Calls { get; private set; }

        public string? LastPrinterName { get; private set; }

        public Task<PdfPrintResult> PrintAsync(
            string pdfPath,
            string printerName,
            int copies,
            CancellationToken cancellationToken)
        {
            Calls++;
            LastPrinterName = printerName;
            AssertTrue(File.Exists(pdfPath), "PDF powinien istnieć przed przekazaniem do drukarki.");
            return Task.FromResult(result);
        }
    }

    private sealed class NullLogger : IAppLogger
    {
        public void Information(string message)
        {
        }

        public void Warning(string message)
        {
        }

        public void Error(string message, Exception? exception = null)
        {
        }
    }
}
