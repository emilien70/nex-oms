using System.IO;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Threading;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.Wpf;
using NexPrinter.Core.Printing;

namespace NexPrinter.App;

public sealed class WebViewPdfPrinter : IPdfPrinter, IAsyncDisposable
{
    private readonly Dispatcher _dispatcher;
    private readonly string _userDataDirectory;
    private readonly SemaphoreSlim _printGate = new(1, 1);
    private Window? _hostWindow;
    private WebView2? _webView;

    public WebViewPdfPrinter(Dispatcher dispatcher, string userDataDirectory)
    {
        _dispatcher = dispatcher ?? throw new ArgumentNullException(nameof(dispatcher));
        ArgumentException.ThrowIfNullOrWhiteSpace(userDataDirectory);
        _userDataDirectory = userDataDirectory;
    }

    public static bool TryGetRuntimeVersion(out string version)
    {
        try
        {
            version = CoreWebView2Environment.GetAvailableBrowserVersionString();
            return !string.IsNullOrWhiteSpace(version);
        }
        catch (WebView2RuntimeNotFoundException)
        {
            version = string.Empty;
            return false;
        }
    }

    public async Task<PdfPrintResult> PrintAsync(
        string pdfPath,
        string printerName,
        int copies,
        CancellationToken cancellationToken)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(pdfPath);
        ArgumentException.ThrowIfNullOrWhiteSpace(printerName);

        await _printGate.WaitAsync(cancellationToken).ConfigureAwait(false);
        try
        {
            if (!File.Exists(pdfPath))
            {
                return PdfPrintResult.Failure(
                    "local_payload_missing",
                    "Lokalny plik PDF nie jest dostępny.");
            }

            try
            {
                var operation = _dispatcher.InvokeAsync(
                    () => PrintOnUiThreadAsync(pdfPath, printerName, copies, cancellationToken),
                    DispatcherPriority.Normal,
                    cancellationToken);
                return await (await operation.Task.ConfigureAwait(false)).ConfigureAwait(false);
            }
            catch (WebView2RuntimeNotFoundException)
            {
                return PdfPrintResult.Failure(
                    "webview2_runtime_missing",
                    "Brakuje środowiska Microsoft Edge WebView2 Runtime wymaganego do drukowania PDF.");
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception)
            {
                return PdfPrintResult.Failure(
                    "pdf_engine_failed",
                    "Silnik PDF nie mógł przygotować dokumentu do wydruku.");
            }
        }
        finally
        {
            _printGate.Release();
        }
    }

    public async ValueTask DisposeAsync()
    {
        await _printGate.WaitAsync().ConfigureAwait(false);
        try
        {
            await _dispatcher.InvokeAsync(() =>
            {
                _webView?.Dispose();
                _webView = null;
                _hostWindow?.Close();
                _hostWindow = null;
            });
        }
        finally
        {
            _printGate.Release();
            _printGate.Dispose();
        }
    }

    private async Task<PdfPrintResult> PrintOnUiThreadAsync(
        string pdfPath,
        string printerName,
        int copies,
        CancellationToken cancellationToken)
    {
        await EnsureHostAsync();
        var webView = _webView ?? throw new InvalidOperationException("Nie zainicjalizowano silnika PDF.");
        await NavigateAsync(webView, new Uri(Path.GetFullPath(pdfPath)), cancellationToken);

        // The built-in PDF viewer needs a short moment after navigation before accepting PrintAsync.
        await Task.Delay(TimeSpan.FromMilliseconds(400), cancellationToken);

        var settings = webView.CoreWebView2.Environment.CreatePrintSettings();
        settings.PrinterName = printerName;
        settings.Copies = copies;
        settings.ScaleFactor = 1;
        settings.ShouldPrintBackgrounds = true;
        settings.ShouldPrintHeaderAndFooter = false;

        var status = await webView.CoreWebView2.PrintAsync(settings);
        return status switch
        {
            CoreWebView2PrintStatus.Succeeded => PdfPrintResult.Success(),
            CoreWebView2PrintStatus.PrinterUnavailable => PdfPrintResult.Failure(
                "printer_unavailable",
                $"Drukarka „{printerName}” nie jest dostępna."),
            _ => PdfPrintResult.Failure(
                "windows_print_failed",
                "System Windows nie przyjął dokumentu do kolejki drukarki."),
        };
    }

    private async Task EnsureHostAsync()
    {
        if (_webView?.CoreWebView2 is not null)
        {
            return;
        }

        Directory.CreateDirectory(_userDataDirectory);
        _webView = new WebView2();
        _hostWindow = new Window
        {
            Width = 4,
            Height = 4,
            Left = -10000,
            Top = -10000,
            Opacity = 0.01,
            ShowActivated = false,
            ShowInTaskbar = false,
            WindowStyle = WindowStyle.None,
            ResizeMode = ResizeMode.NoResize,
            Content = new Grid
            {
                Children = { _webView },
            },
        };
        _hostWindow.Show();

        var environment = await CoreWebView2Environment.CreateAsync(
            userDataFolder: _userDataDirectory);
        await _webView.EnsureCoreWebView2Async(environment);
    }

    private static async Task NavigateAsync(
        WebView2 webView,
        Uri uri,
        CancellationToken cancellationToken)
    {
        var completion = new TaskCompletionSource(
            TaskCreationOptions.RunContinuationsAsynchronously);

        void OnNavigationCompleted(object? sender, CoreWebView2NavigationCompletedEventArgs args)
        {
            if (args.IsSuccess)
            {
                completion.TrySetResult();
            }
            else
            {
                completion.TrySetException(new InvalidOperationException(
                    $"Nie udało się otworzyć PDF ({args.WebErrorStatus})."));
            }
        }

        webView.NavigationCompleted += OnNavigationCompleted;
        try
        {
            webView.Source = uri;
            await completion.Task.WaitAsync(TimeSpan.FromSeconds(15), cancellationToken);
        }
        finally
        {
            webView.NavigationCompleted -= OnNavigationCompleted;
        }
    }
}
