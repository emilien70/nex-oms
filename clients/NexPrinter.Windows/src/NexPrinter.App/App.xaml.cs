using System.Drawing;
using System.IO;
using System.Net;
using System.Net.Http;
using System.Security;
using System.Windows;
using NexPrinter.Core;
using NexPrinter.Core.Api;
using NexPrinter.Core.Configuration;
using NexPrinter.Core.Logging;
using NexPrinter.Core.Printing;
using NexPrinter.Core.Synchronization;
using WinForms = System.Windows.Forms;
using WpfMessageBox = System.Windows.MessageBox;

namespace NexPrinter.App;

public partial class App : System.Windows.Application
{
    private const string MutexName = @"Local\NEXPrinter.Client";
    private const string ActivationEventName = @"Local\NEXPrinter.ShowMainWindow";
    private Mutex? _singleInstanceMutex;
    private EventWaitHandle? _activationEvent;
    private RegisteredWaitHandle? _activationRegistration;
    private HttpClient? _httpClient;
    private RollingFileLogger? _logger;
    private ClientSynchronizationService? _synchronizationService;
    private PrintJobWorker? _printJobWorker;
    private WebViewPdfPrinter? _pdfPrinter;
    private SecureClientCredentialStore? _credentialStore;
    private IClientApplicationSettingsStore? _applicationSettingsStore;
    private MainWindow? _mainWindow;
    private WinForms.NotifyIcon? _trayIcon;
    private Icon? _applicationIcon;
    private bool _isExiting;
    private bool _servicesDisposed;

    protected override async void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);
        var launchOptions = ClientLaunchOptions.Parse(e.Args);

        _singleInstanceMutex = new Mutex(initiallyOwned: true, MutexName, out var isFirstInstance);
        if (!isFirstInstance)
        {
            if (!launchOptions.StartInBackground && !TryRequestExistingWindow())
            {
                WpfMessageBox.Show(
                    "NEX Printer jest już uruchomiony. Otwórz program z ikony w zasobniku systemowym.",
                    ClientConstants.ApplicationName,
                    MessageBoxButton.OK,
                    MessageBoxImage.Information);
            }

            Shutdown();
            return;
        }

        StartActivationListener();

        ClientDataDirectoryResult dataDirectory;
        try
        {
            dataDirectory = ClientDataDirectory.Prepare(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData));
        }
        catch (Exception exception) when (exception is
            IOException or
            UnauthorizedAccessException or
            ArgumentException)
        {
            WpfMessageBox.Show(
                "NEX Printer nie może przygotować katalogu danych %LOCALAPPDATA%\\NEX Printer. " +
                "Sprawdź uprawnienia użytkownika i wolne miejsce na dysku.",
                ClientConstants.ApplicationName,
                MessageBoxButton.OK,
                MessageBoxImage.Error);
            Shutdown();
            return;
        }

        var applicationDirectory = dataDirectory.Path;
        _logger = new RollingFileLogger(Path.Combine(applicationDirectory, "logs"));
        if (dataDirectory.ImportedLegacyData)
        {
            _logger.Information(
                "Skopiowano brakujące dane z poprzedniego katalogu %LOCALAPPDATA%\\NEX\\NEX Printer.");
        }

        if (!WebViewPdfPrinter.TryGetRuntimeVersion(out var webViewVersion))
        {
            _logger.Error("Nie znaleziono Microsoft Edge WebView2 Runtime.");
            WpfMessageBox.Show(
                "NEX Printer wymaga Microsoft Edge WebView2 Runtime do drukowania plików PDF. " +
                "Zainstaluj WebView2 Runtime i uruchom program ponownie.",
                ClientConstants.ApplicationName,
                MessageBoxButton.OK,
                MessageBoxImage.Error);
            Shutdown();
            return;
        }

        _logger.Information(
            $"Uruchomiono NEX Printer {ClientConstants.ClientVersion}; WebView2 {webViewVersion}.");
        var executablePath = Environment.ProcessPath
            ?? throw new InvalidOperationException("Nie można ustalić ścieżki NEX Printer.");
        var autostartService = new WindowsAutostartService(
            new WindowsAutostartRegistry(),
            executablePath);
        _applicationSettingsStore = new JsonClientApplicationSettingsStore(applicationDirectory);
        try
        {
            var initialAutostartPreference = InstallerAutostartPreference.Read(AppContext.BaseDirectory);
            if (AutostartPreferenceInitializer.InitializeIfNeeded(
                    _applicationSettingsStore,
                    autostartService,
                    initialAutostartPreference))
            {
                _logger.Information(initialAutostartPreference == true
                    ? "Włączono autostart wybrany podczas instalacji."
                    : "Pozostawiono autostart wyłączony zgodnie z wyborem podczas instalacji.");
            }

            autostartService.RepairExecutablePathIfEnabled();
        }
        catch (Exception exception) when (exception is
            IOException or
            UnauthorizedAccessException or
            SecurityException)
        {
            _logger.Warning($"Nie udało się zaktualizować ścieżki autostartu: {exception.Message}");
        }

        var dataProtector = new WindowsDpapiProtector();
        _credentialStore = new SecureClientCredentialStore(applicationDirectory, dataProtector);
        _httpClient = new HttpClient(new SocketsHttpHandler
        {
            AutomaticDecompression = DecompressionMethods.All,
            AllowAutoRedirect = false,
            ConnectTimeout = TimeSpan.FromSeconds(10),
        })
        {
            Timeout = TimeSpan.FromSeconds(15),
        };

        var apiClient = new NexPrinterApiClient(_httpClient);
        var printerCatalog = new WindowsPrinterCatalog();
        _synchronizationService = new ClientSynchronizationService(
            apiClient,
            _credentialStore,
            printerCatalog,
            _logger);
        _pdfPrinter = new WebViewPdfPrinter(
            Dispatcher,
            Path.Combine(applicationDirectory, "WebView2"));
        var printHistoryStore = new JsonPrintHistoryStore(applicationDirectory);
        _printJobWorker = new PrintJobWorker(
            apiClient,
            _credentialStore,
            printerCatalog,
            new SecurePrintJobJournalStore(applicationDirectory, dataProtector),
            printHistoryStore,
            _pdfPrinter,
            _logger);
        _printJobWorker.StatusChanged += PrintJobWorker_StatusChanged;

        _mainWindow = new MainWindow(
            apiClient,
            _credentialStore,
            _synchronizationService,
            _printJobWorker,
            printHistoryStore,
            _applicationSettingsStore,
            autostartService,
            Path.Combine(applicationDirectory, "logs"),
            _logger);
        MainWindow = _mainWindow;
        CreateTrayIcon();

        DispatcherUnhandledException += (_, args) =>
        {
            _logger.Error("Nieobsłużony błąd interfejsu NEX Printer.", args.Exception);
            WpfMessageBox.Show(
                "Wystąpił nieoczekiwany błąd. Szczegóły zapisano w dzienniku programu.",
                ClientConstants.ApplicationName,
                MessageBoxButton.OK,
                MessageBoxImage.Error);
            args.Handled = true;
        };

        AppDomain.CurrentDomain.UnhandledException += (_, args) =>
        {
            _logger.Error("Nieobsłużony błąd NEX Printer.", args.ExceptionObject as Exception);
        };

        await _synchronizationService.StartAsync();
        await _printJobWorker.StartAsync();

        if (launchOptions.StartInBackground)
        {
            _logger.Information("NEX Printer uruchomiono w tle.");
        }
        else
        {
            ShowMainWindow();
        }
    }

    protected override void OnExit(ExitEventArgs e)
    {
        _isExiting = true;
        _trayIcon?.Dispose();
        _applicationIcon?.Dispose();
        _httpClient?.Dispose();
        _activationRegistration?.Unregister(null);
        _activationEvent?.Dispose();

        if (_singleInstanceMutex is not null)
        {
            try
            {
                _singleInstanceMutex.ReleaseMutex();
            }
            catch (ApplicationException)
            {
                // The mutex was not acquired by this process.
            }

            _singleInstanceMutex.Dispose();
        }

        base.OnExit(e);
    }

    private void StartActivationListener()
    {
        _activationEvent = new EventWaitHandle(
            initialState: false,
            EventResetMode.AutoReset,
            ActivationEventName);
        _activationRegistration = ThreadPool.RegisterWaitForSingleObject(
            _activationEvent,
            (_, timedOut) =>
            {
                if (!timedOut && !_isExiting)
                {
                    Dispatcher.BeginInvoke(() => ShowMainWindow());
                }
            },
            state: null,
            Timeout.InfiniteTimeSpan,
            executeOnlyOnce: false);
    }

    private static bool TryRequestExistingWindow()
    {
        try
        {
            using var activationEvent = EventWaitHandle.OpenExisting(ActivationEventName);
            return activationEvent.Set();
        }
        catch (WaitHandleCannotBeOpenedException)
        {
            return false;
        }
    }

    private void CreateTrayIcon()
    {
        var menu = new WinForms.ContextMenuStrip();
        menu.Items.Add("Otwórz NEX Printer", null, (_, _) => Dispatcher.Invoke(ShowMainWindow));
        menu.Items.Add("Synchronizuj teraz", null, async (_, _) =>
        {
            if (_synchronizationService is not null)
            {
                await _synchronizationService.SynchronizeNowAsync();
            }

            if (_printJobWorker is not null)
            {
                await _printJobWorker.ProcessOnceAsync();
            }
        });
        menu.Items.Add(new WinForms.ToolStripSeparator());
        menu.Items.Add("Zakończ", null, (_, _) => Dispatcher.Invoke(ExitApplication));

        _applicationIcon = LoadApplicationIcon();
        _trayIcon = new WinForms.NotifyIcon
        {
            ContextMenuStrip = menu,
            Icon = _applicationIcon,
            Text = ClientConstants.ApplicationName,
            Visible = true,
        };
        _trayIcon.DoubleClick += (_, _) => Dispatcher.Invoke(ShowMainWindow);
    }

    private static Icon LoadApplicationIcon()
    {
        var resource = System.Windows.Application.GetResourceStream(
            new Uri("pack://application:,,,/Assets/NexPrinter.ico"))
            ?? throw new InvalidOperationException("Nie znaleziono ikony aplikacji NEX Printer.");

        using var stream = resource.Stream;
        using var icon = new Icon(stream);

        return (Icon)icon.Clone();
    }

    private void PrintJobWorker_StatusChanged(object? sender, PrintJobWorkerStatus status)
    {
        if (status.State != PrintJobWorkerState.Failed)
        {
            return;
        }

        if (_applicationSettingsStore?.Load().ShowErrorNotifications == false)
        {
            return;
        }

        Dispatcher.Invoke(() => _trayIcon?.ShowBalloonTip(
            5000,
            "NEX Printer",
            status.Message,
            WinForms.ToolTipIcon.Error));
    }

    private void ShowMainWindow()
    {
        if (_mainWindow is null)
        {
            return;
        }

        _mainWindow.Show();
        _mainWindow.WindowState = WindowState.Normal;
        _mainWindow.Activate();
        _mainWindow.Focus();
    }

    private async void ExitApplication()
    {
        if (_isExiting)
        {
            return;
        }

        _isExiting = true;
        try
        {
            await DisposeServicesAsync();
        }
        catch (Exception exception)
        {
            _logger?.Error("Nie udało się w pełni zamknąć usług NEX Printer.", exception);
        }
        finally
        {
            if (_mainWindow is not null)
            {
                _mainWindow.AllowClose = true;
                _mainWindow.Close();
            }

            Shutdown();
        }
    }

    private async Task DisposeServicesAsync()
    {
        if (_servicesDisposed)
        {
            return;
        }

        _servicesDisposed = true;
        if (_printJobWorker is not null)
        {
            _printJobWorker.StatusChanged -= PrintJobWorker_StatusChanged;
            await _printJobWorker.DisposeAsync();
        }

        if (_synchronizationService is not null)
        {
            await _synchronizationService.DisposeAsync();
        }

        if (_pdfPrinter is not null)
        {
            await _pdfPrinter.DisposeAsync();
        }
    }
}

