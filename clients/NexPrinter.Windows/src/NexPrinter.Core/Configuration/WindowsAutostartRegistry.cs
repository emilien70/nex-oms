using Microsoft.Win32;

namespace NexPrinter.Core.Configuration;

public sealed class WindowsAutostartRegistry : IAutostartRegistry
{
    private const string RunKeyPath = @"Software\Microsoft\Windows\CurrentVersion\Run";
    private const string ValueName = "NEX Printer";

    public string? ReadCommand()
    {
        using var key = Registry.CurrentUser.OpenSubKey(RunKeyPath, writable: false);
        return key?.GetValue(
            ValueName,
            defaultValue: null,
            RegistryValueOptions.DoNotExpandEnvironmentNames) as string;
    }

    public void WriteCommand(string command)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(command);

        using var key = Registry.CurrentUser.CreateSubKey(RunKeyPath, writable: true)
            ?? throw new UnauthorizedAccessException("Nie można otworzyć ustawień autostartu Windows.");
        key.SetValue(ValueName, command, RegistryValueKind.String);
    }

    public void DeleteCommand()
    {
        using var key = Registry.CurrentUser.OpenSubKey(RunKeyPath, writable: true);
        key?.DeleteValue(ValueName, throwOnMissingValue: false);
    }
}
