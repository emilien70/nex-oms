using System.ComponentModel;
using System.Diagnostics;
using System.IO;
using System.Net.Http;
using System.Security;
using System.Windows;
using NexPrinter.Core;
using NexPrinter.Core.Api;
using NexPrinter.Core.Configuration;
using NexPrinter.Core.Logging;
using NexPrinter.Core.Printing;
using NexPrinter.Core.Synchronization;
using MediaBrush = System.Windows.Media.Brush;
using MediaColor = System.Windows.Media.Color;
using MediaSolidColorBrush = System.Windows.Media.SolidColorBrush;
using WpfMessageBox = System.Windows.MessageBox;

namespace NexPrinter.App;

public partial class MainWindow : Window
{
    private static readonly MediaBrush ConnectedForeground = new MediaSolidColorBrush(MediaColor.FromRgb(17, 114, 59));
    private static readonly MediaBrush PendingForeground = new MediaSolidColorBrush(MediaColor.FromRgb(23, 92, 211));
    private static readonly MediaBrush OfflineForeground = new MediaSolidColorBrush(MediaColor.FromRgb(180, 35, 24));

    private readonly INexPrinterApiClient _apiClient;
    private readonly IClientCredentialStore _credentialStore;
    private readonly ClientSynchronizationService _synchronizationService;
    private readonly PrintJobWorker _printJobWorker;
    private readonly IPrintHistoryStore _printHistoryStore;
    private readonly IClientApplicationSettingsStore _applicationSettingsStore;
    private readonly IAutostartService _autostartService;
    private readonly string _logDirectory;
    private readonly IAppLogger _logger;

    public MainWindow(
        INexPrinterApiClient apiClient,
        IClientCredentialStore credentialStore,
        ClientSynchronizationService synchronizationService,
        PrintJobWorker printJobWorker,
        IPrintHistoryStore printHistoryStore,
        IClientApplicationSettingsStore applicationSettingsStore,
        IAutostartService autostartService,
        string logDirectory,
        IAppLogger logger)
    {
        InitializeComponent();
        _apiClient = apiClient;
        _credentialStore = credentialStore;
        _synchronizationService = synchronizationService;
        _printJobWorker = printJobWorker;
        _printHistoryStore = printHistoryStore;
        _applicationSettingsStore = applicationSettingsStore;
        _autostartService = autostartService;
        _logDirectory = logDirectory;
        _logger = logger;

        Title = $"{ClientConstants.ApplicationName} [v{ClientConstants.ClientVersion}]";
        HomeVersionText.Text = $"v{ClientConstants.ClientVersion}";
        SettingsVersionText.Text = $"Wersja {ClientConstants.ClientVersion}";
        ServerUrlTextBox.Text = ClientConstants.DefaultServerUrl;
        ComputerNameTextBox.Text = Environment.MachineName;
        _synchronizationService.StatusChanged += SynchronizationService_StatusChanged;
        _printJobWorker.StatusChanged += PrintJobWorker_StatusChanged;
        Loaded += MainWindow_Loaded;
    }

    public bool AllowClose { get; set; }

    protected override void OnClosing(CancelEventArgs e)
    {
        if (!AllowClose)
        {
            e.Cancel = true;
            if (_credentialStore.Load() is null)
            {
                ShowSettingsView();
            }
            else
            {
                ShowHomeView();
            }

            Hide();
            return;
        }

        _synchronizationService.StatusChanged -= SynchronizationService_StatusChanged;
        _printJobWorker.StatusChanged -= PrintJobWorker_StatusChanged;
        base.OnClosing(e);
    }

    private void MainWindow_Loaded(object sender, RoutedEventArgs e)
    {
        RefreshView();
        RefreshHistory();
        RefreshApplicationSettings();
        RenderPrintJobStatus(_printJobWorker.CurrentStatus);

        if (_credentialStore.Load() is null)
        {
            ShowSettingsView();
        }
        else
        {
            ShowHomeView();
        }
    }

    private async void ConnectButton_Click(object sender, RoutedEventArgs e)
    {
        PairingErrorText.Visibility = Visibility.Collapsed;
        ConnectButton.IsEnabled = false;

        try
        {
            var serverUrl = ServerAddressPolicy.Normalize(ServerUrlTextBox.Text);
            var pairingCode = PairingCodeTextBox.Text.Trim();
            var computerName = ComputerNameTextBox.Text.Trim();

            if (string.IsNullOrWhiteSpace(pairingCode))
            {
                throw new ArgumentException("Wpisz kod połączenia utworzony w NEX-OMS.");
            }

            if (string.IsNullOrWhiteSpace(computerName))
            {
                throw new ArgumentException("Wpisz nazwę tego komputera.");
            }

            var result = await _apiClient.PairAsync(
                serverUrl,
                pairingCode,
                computerName,
                CancellationToken.None);
            _credentialStore.Save(new ClientCredentials(
                serverUrl,
                result.AgentId,
                result.AgentName,
                result.Token,
                DateTimeOffset.UtcNow));

            PairingCodeTextBox.Clear();
            _logger.Information("Program został połączony z NEX-OMS.");
            RefreshView();
            await _synchronizationService.RestartAsync();
            ShowHomeView();
        }
        catch (Exception exception) when (exception is ArgumentException or NexPrinterApiException or HttpRequestException or TaskCanceledException or IOException)
        {
            _logger.Error("Nie udało się połączyć programu z NEX-OMS.", exception);
            PairingErrorText.Text = UserMessage(exception);
            PairingErrorText.Visibility = Visibility.Visible;
        }
        finally
        {
            ConnectButton.IsEnabled = true;
        }
    }

    private async void DisconnectButton_Click(object sender, RoutedEventArgs e)
    {
        var answer = WpfMessageBox.Show(
            "Usunąć zapisane połączenie z tego komputera? Program trzeba będzie połączyć ponownie kodem z NEX-OMS.",
            ClientConstants.ApplicationName,
            MessageBoxButton.YesNo,
            MessageBoxImage.Question);

        if (answer != MessageBoxResult.Yes)
        {
            return;
        }

        _credentialStore.Clear();
        _logger.Information("Usunięto lokalne dane połączenia.");
        RefreshView();
        await _synchronizationService.RestartAsync();
        ShowSettingsView();
    }

    private void ShowLogsButton_Click(object sender, RoutedEventArgs e)
    {
        RefreshHistory();
        ShowLogsView();
    }

    private void ShowSettingsButton_Click(object sender, RoutedEventArgs e)
    {
        RefreshView();
        RefreshApplicationSettings();
        ShowSettingsView();
    }

    private void ApplicationSettingToggle_Click(object sender, RoutedEventArgs e)
    {
        var previous = _applicationSettingsStore.Load();
        var updated = previous with
        {
            ShowErrorNotifications = ShowErrorNotificationsToggle.IsChecked == true,
        };

        try
        {
            _applicationSettingsStore.Save(updated);
            _logger.Information("Zapisano ustawienia programu NEX Printer.");
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            _logger.Error("Nie udało się zapisać ustawień programu.", exception);
            ShowErrorNotificationsToggle.IsChecked = previous.ShowErrorNotifications;
            WpfMessageBox.Show(
                "Nie udało się zapisać ustawień programu.",
                ClientConstants.ApplicationName,
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
        }
    }

    private void AutostartToggle_Click(object sender, RoutedEventArgs e)
    {
        var requestedState = AutostartToggle.IsChecked == true;
        var previousSettings = _applicationSettingsStore.Load();
        var previousState = !requestedState;

        try
        {
            previousState = _autostartService.IsEnabled();
            _autostartService.SetEnabled(requestedState);
            _applicationSettingsStore.Save(previousSettings with
            {
                AutostartPreferenceInitialized = true,
            });
            AutostartStatusText.Text = requestedState
                ? "Program uruchomi się w tle i pozostanie w zasobniku systemowym."
                : "Autostart jest wyłączony.";
            _logger.Information(requestedState
                ? "Włączono uruchamianie NEX Printer razem z Windows."
                : "Wyłączono uruchamianie NEX Printer razem z Windows.");
        }
        catch (Exception exception) when (exception is
            IOException or
            UnauthorizedAccessException or
            SecurityException)
        {
            _logger.Error("Nie udało się zmienić ustawień autostartu.", exception);
            try
            {
                _autostartService.SetEnabled(previousState);
            }
            catch (Exception rollbackException) when (rollbackException is
                IOException or
                UnauthorizedAccessException or
                SecurityException)
            {
                _logger.Error("Nie udało się przywrócić poprzedniego ustawienia autostartu.", rollbackException);
            }

            AutostartToggle.IsChecked = previousState;
            WpfMessageBox.Show(
                "Nie udało się zmienić ustawień uruchamiania razem z Windows.",
                ClientConstants.ApplicationName,
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
        }
    }

    private async void SynchronizeNowButton_Click(object sender, RoutedEventArgs e)
    {
        SynchronizeNowButton.IsEnabled = false;

        try
        {
            var synchronized = await _synchronizationService.SynchronizeNowAsync();
            if (synchronized)
            {
                await _printJobWorker.ProcessOnceAsync();
            }
        }
        catch (Exception exception) when (exception is HttpRequestException or TaskCanceledException or IOException or NexPrinterApiException or PrintJobException)
        {
            _logger.Error("Ręczna synchronizacja NEX Printer nie powiodła się.", exception);
            WpfMessageBox.Show(
                "Nie udało się zsynchronizować programu z NEX-OMS.",
                ClientConstants.ApplicationName,
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
        }
        finally
        {
            SynchronizeNowButton.IsEnabled = true;
        }
    }

    private void BackToHomeButton_Click(object sender, RoutedEventArgs e)
    {
        ShowHomeView();
    }

    private void OpenLogsFolderButton_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            Directory.CreateDirectory(_logDirectory);
            Process.Start(new ProcessStartInfo(_logDirectory)
            {
                UseShellExecute = true,
            });
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or InvalidOperationException)
        {
            _logger.Error("Nie udało się otworzyć folderu logów.", exception);
            WpfMessageBox.Show(
                "Nie można otworzyć folderu logów.",
                ClientConstants.ApplicationName,
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
        }
    }

    private void SynchronizationService_StatusChanged(object? sender, ConnectionStatus status)
    {
        Dispatcher.Invoke(() =>
        {
            RenderStatus(status);
            if (status.State == ConnectionState.PairingRequired)
            {
                RefreshView();
                ShowSettingsView();
            }
        });
    }

    private void PrintJobWorker_StatusChanged(object? sender, PrintJobWorkerStatus status)
    {
        Dispatcher.Invoke(() =>
        {
            RenderPrintJobStatus(status);
            if (status.State is PrintJobWorkerState.Succeeded or PrintJobWorkerState.Failed)
            {
                RefreshHistory();
            }
        });
    }

    private void RefreshView()
    {
        var credentials = _credentialStore.Load();
        var connected = credentials is not null;
        PairingPanel.Visibility = connected ? Visibility.Collapsed : Visibility.Visible;
        ConnectedPanel.Visibility = connected ? Visibility.Visible : Visibility.Collapsed;

        if (credentials is not null)
        {
            ConnectedComputerText.Text = credentials.AgentName;
            ConnectedServerText.Text = credentials.ServerUrl.ToString().TrimEnd('/');
            ServerUrlTextBox.Text = credentials.ServerUrl.ToString().TrimEnd('/');
        }

        RenderStatus(connected
            ? _synchronizationService.CurrentStatus
            : new ConnectionStatus(
                ConnectionState.Unpaired,
                "Utwórz kod połączenia w integracji NEX Printer i wpisz go poniżej.",
                DateTimeOffset.Now));
    }

    private void RefreshHistory()
    {
        try
        {
            var items = _printHistoryStore
                .GetRecent(1000)
                .Select(PrintHistoryListItem.FromEntry)
                .ToArray();

            LogsDataGrid.ItemsSource = items;
            EmptyLogsText.Visibility = items.Length == 0
                ? Visibility.Visible
                : Visibility.Collapsed;
            LogsCountText.Text = $"Liczba wpisów: {items.Length}";
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            _logger.Error("Nie udało się odczytać lokalnej historii wydruków.", exception);
            LogsDataGrid.ItemsSource = Array.Empty<PrintHistoryListItem>();
            EmptyLogsText.Text = "Nie udało się odczytać historii wydruków.";
            EmptyLogsText.Visibility = Visibility.Visible;
            LogsCountText.Text = string.Empty;
        }
    }

    private void RefreshApplicationSettings()
    {
        var settings = _applicationSettingsStore.Load();
        ShowErrorNotificationsToggle.IsChecked = settings.ShowErrorNotifications;

        try
        {
            AutostartToggle.IsEnabled = true;
            AutostartToggle.IsChecked = _autostartService.IsEnabled();
            AutostartStatusText.Text = AutostartToggle.IsChecked == true
                ? "Program uruchomi się w tle i pozostanie w zasobniku systemowym."
                : "Autostart jest wyłączony.";
        }
        catch (Exception exception) when (exception is
            IOException or
            UnauthorizedAccessException or
            SecurityException)
        {
            _logger.Error("Nie udało się odczytać ustawień autostartu.", exception);
            AutostartToggle.IsChecked = false;
            AutostartToggle.IsEnabled = false;
            AutostartStatusText.Text = "Nie można odczytać ustawień autostartu Windows.";
        }
    }

    private void RenderStatus(ConnectionStatus status)
    {
        var (label, title, foreground) = status.State switch
        {
            ConnectionState.Connected => ("Połączony", "Połączono", ConnectedForeground),
            ConnectionState.Connecting => ("Łączenie", "Łączenie", PendingForeground),
            ConnectionState.Offline => ("Brak połączenia", "Brak połączenia", OfflineForeground),
            ConnectionState.PairingRequired => ("Rozłączony", "Połącz ponownie", OfflineForeground),
            _ => ("Niepołączony", "Brak połączenia", PendingForeground),
        };

        HomeStatusDot.Fill = foreground;
        HomeStatusText.Foreground = foreground;
        HomeStatusText.Text = label;
        SettingsStatusDot.Fill = foreground;
        SettingsStatusTitle.Foreground = foreground;
        SettingsStatusTitle.Text = title;
        SettingsStatusMessage.Text = status.Message;
        LastConnectionText.Text = status.LastSuccessfulConnectionAt is null
            ? string.Empty
            : $"Ostatnio: {status.LastSuccessfulConnectionAt:dd.MM.yyyy HH:mm:ss}";
    }

    private void RenderPrintJobStatus(PrintJobWorkerStatus status)
    {
        PrintJobStatusTitle.Text = status.Title;
        PrintJobStatusMessage.Text = status.Message;
        PrintJobStatusDetails.Text = string.Join(
            "  •  ",
            new[]
            {
                string.IsNullOrWhiteSpace(status.DocumentNumber)
                    ? null
                    : $"Dokument: {status.DocumentNumber}",
                string.IsNullOrWhiteSpace(status.PrinterName)
                    ? null
                    : $"Drukarka: {status.PrinterName}",
            }.Where(value => value is not null));
        PrintJobStatusDetails.Visibility = string.IsNullOrWhiteSpace(PrintJobStatusDetails.Text)
            ? Visibility.Collapsed
            : Visibility.Visible;
        PrintJobStatusTime.Text = status.ChangedAt.ToString("dd.MM.yyyy HH:mm:ss");
    }

    private void ShowHomeView()
    {
        HomeView.Visibility = Visibility.Visible;
        LogsView.Visibility = Visibility.Collapsed;
        SettingsView.Visibility = Visibility.Collapsed;
    }

    private void ShowLogsView()
    {
        HomeView.Visibility = Visibility.Collapsed;
        LogsView.Visibility = Visibility.Visible;
        SettingsView.Visibility = Visibility.Collapsed;
    }

    private void ShowSettingsView()
    {
        HomeView.Visibility = Visibility.Collapsed;
        LogsView.Visibility = Visibility.Collapsed;
        SettingsView.Visibility = Visibility.Visible;
    }

    private static string UserMessage(Exception exception) => exception switch
    {
        ArgumentException => exception.Message,
        NexPrinterApiException => exception.Message,
        TaskCanceledException => "Serwer NEX-OMS nie odpowiedział w wymaganym czasie.",
        _ => "Nie można połączyć się z NEX-OMS. Sprawdź adres i uruchomienie serwera.",
    };

    private sealed record PrintHistoryListItem(
        string DocumentNumber,
        string PrinterName,
        string Status,
        string CompletedAt,
        string Details)
    {
        public static PrintHistoryListItem FromEntry(PrintHistoryEntry entry) => new(
            entry.DocumentNumber,
            entry.PrinterName,
            entry.Result == PrintHistoryResult.Succeeded ? "Wydrukowano" : "Błąd",
            entry.CompletedAt.ToLocalTime().ToString("dd.MM.yyyy HH:mm:ss"),
            entry.Message);
    }
}
