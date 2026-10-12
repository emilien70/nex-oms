using System.Text.RegularExpressions;

namespace NexPrinter.Core.Logging;

public sealed partial class RollingFileLogger : IAppLogger
{
    private const long MaximumFileSize = 1_048_576;
    private const int RetainedFiles = 4;
    private readonly object _sync = new();
    private readonly string _logPath;

    public RollingFileLogger(string logDirectory)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(logDirectory);
        Directory.CreateDirectory(logDirectory);
        _logPath = Path.Combine(logDirectory, "nex-printer.log");
    }

    public void Information(string message) => Write("INFO", message, null);

    public void Warning(string message) => Write("WARN", message, null);

    public void Error(string message, Exception? exception = null) => Write("ERROR", message, exception);

    private void Write(string level, string message, Exception? exception)
    {
        lock (_sync)
        {
            try
            {
                RotateIfNeeded();
                var safeMessage = Redact(message);
                var exceptionText = exception is null
                    ? string.Empty
                    : $" | {exception.GetType().Name}: {Redact(exception.Message)}";
                var line = $"{DateTimeOffset.Now:yyyy-MM-dd HH:mm:ss.fff zzz} [{level}] {safeMessage}{exceptionText}{Environment.NewLine}";
                File.AppendAllText(_logPath, line);
            }
            catch (IOException)
            {
                // Logging must never stop synchronization.
            }
            catch (UnauthorizedAccessException)
            {
                // Logging must never stop synchronization.
            }
        }
    }

    private void RotateIfNeeded()
    {
        if (!File.Exists(_logPath) || new FileInfo(_logPath).Length < MaximumFileSize)
        {
            return;
        }

        for (var index = RetainedFiles; index >= 1; index--)
        {
            var source = index == 1 ? _logPath : $"{_logPath}.{index - 1}";
            var destination = $"{_logPath}.{index}";
            if (File.Exists(source))
            {
                File.Move(source, destination, true);
            }
        }
    }

    private static string Redact(string value) => TokenPattern().Replace(value, "[ukryty-token]");

    [GeneratedRegex("(?<![0-9a-fA-F])[0-9a-fA-F]{64}(?![0-9a-fA-F])", RegexOptions.CultureInvariant)]
    private static partial Regex TokenPattern();
}

