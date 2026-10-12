using System.Text.Json;

namespace NexPrinter.Core.Configuration;

public sealed class JsonClientApplicationSettingsStore : IClientApplicationSettingsStore
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        WriteIndented = true,
    };

    private readonly object _sync = new();
    private readonly string _settingsPath;

    public JsonClientApplicationSettingsStore(string rootDirectory)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(rootDirectory);
        Directory.CreateDirectory(rootDirectory);
        _settingsPath = Path.Combine(rootDirectory, "settings.json");
    }

    public ClientApplicationSettings Load()
    {
        lock (_sync)
        {
            if (!File.Exists(_settingsPath))
            {
                return new ClientApplicationSettings();
            }

            try
            {
                return JsonSerializer.Deserialize<ClientApplicationSettings>(
                        File.ReadAllText(_settingsPath),
                        JsonOptions)
                    ?? new ClientApplicationSettings();
            }
            catch (Exception exception) when (exception is JsonException or IOException or UnauthorizedAccessException)
            {
                return new ClientApplicationSettings();
            }
        }
    }

    public void Save(ClientApplicationSettings settings)
    {
        ArgumentNullException.ThrowIfNull(settings);

        lock (_sync)
        {
            var temporaryPath = $"{_settingsPath}.tmp";
            File.WriteAllText(temporaryPath, JsonSerializer.Serialize(settings, JsonOptions));
            File.Move(temporaryPath, _settingsPath, true);
        }
    }
}
