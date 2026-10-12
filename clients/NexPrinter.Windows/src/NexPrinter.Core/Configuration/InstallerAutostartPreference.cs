namespace NexPrinter.Core.Configuration;

public static class InstallerAutostartPreference
{
    public const string FileName = "initial-autostart.preference";

    public static bool? Read(string applicationDirectory)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(applicationDirectory);

        var path = Path.Combine(applicationDirectory, FileName);
        if (!File.Exists(path))
        {
            return null;
        }

        return File.ReadAllText(path).Trim().ToLowerInvariant() switch
        {
            "enabled" => true,
            "disabled" => false,
            _ => null,
        };
    }
}
