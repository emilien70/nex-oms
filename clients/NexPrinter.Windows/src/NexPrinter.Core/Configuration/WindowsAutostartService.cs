namespace NexPrinter.Core.Configuration;

public sealed class WindowsAutostartService : IAutostartService
{
    private readonly IAutostartRegistry _registry;
    private readonly string _startupCommand;

    public WindowsAutostartService(
        IAutostartRegistry registry,
        string executablePath)
    {
        _registry = registry ?? throw new ArgumentNullException(nameof(registry));
        ArgumentException.ThrowIfNullOrWhiteSpace(executablePath);

        var fullPath = Path.GetFullPath(executablePath);
        if (fullPath.Contains('"'))
        {
            throw new ArgumentException("Ścieżka programu nie może zawierać cudzysłowu.", nameof(executablePath));
        }

        _startupCommand = $"\"{fullPath}\" --background";
    }

    public bool IsEnabled() => !string.IsNullOrWhiteSpace(_registry.ReadCommand());

    public void SetEnabled(bool enabled)
    {
        if (enabled)
        {
            _registry.WriteCommand(_startupCommand);
            return;
        }

        _registry.DeleteCommand();
    }

    public void RepairExecutablePathIfEnabled()
    {
        var currentCommand = _registry.ReadCommand();
        if (string.IsNullOrWhiteSpace(currentCommand)
            || string.Equals(currentCommand, _startupCommand, StringComparison.OrdinalIgnoreCase))
        {
            return;
        }

        _registry.WriteCommand(_startupCommand);
    }
}
