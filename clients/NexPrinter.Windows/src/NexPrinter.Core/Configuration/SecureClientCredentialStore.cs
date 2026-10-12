using System.Text.Json;

namespace NexPrinter.Core.Configuration;

public sealed class SecureClientCredentialStore : IClientCredentialStore
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        WriteIndented = true,
    };

    private readonly object _sync = new();
    private readonly IDataProtector _protector;
    private readonly string _configurationPath;
    private readonly string _tokenPath;

    public SecureClientCredentialStore(string rootDirectory, IDataProtector protector)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(rootDirectory);
        _protector = protector ?? throw new ArgumentNullException(nameof(protector));
        _configurationPath = Path.Combine(rootDirectory, "client.json");
        _tokenPath = Path.Combine(rootDirectory, "token.dat");
    }

    public ClientCredentials? Load()
    {
        lock (_sync)
        {
            if (!File.Exists(_configurationPath) || !File.Exists(_tokenPath))
            {
                return null;
            }

            try
            {
                var stored = JsonSerializer.Deserialize<StoredConfiguration>(
                    File.ReadAllText(_configurationPath),
                    JsonOptions);

                if (stored is null)
                {
                    return null;
                }

                var tokenBytes = _protector.Unprotect(File.ReadAllBytes(_tokenPath));
                var token = System.Text.Encoding.UTF8.GetString(tokenBytes);
                Array.Clear(tokenBytes);

                return new ClientCredentials(
                    ServerAddressPolicy.Normalize(stored.ServerUrl),
                    stored.AgentId,
                    stored.AgentName,
                    token,
                    stored.PairedAtUtc);
            }
            catch (JsonException)
            {
                return null;
            }
            catch (FormatException)
            {
                return null;
            }
            catch (ArgumentException)
            {
                return null;
            }
        }
    }

    public void Save(ClientCredentials credentials)
    {
        ArgumentNullException.ThrowIfNull(credentials);

        lock (_sync)
        {
            var directory = Path.GetDirectoryName(_configurationPath)
                ?? throw new InvalidOperationException("Nie można ustalić katalogu konfiguracji.");
            Directory.CreateDirectory(directory);

            var stored = new StoredConfiguration(
                credentials.ServerUrl.ToString().TrimEnd('/'),
                credentials.AgentId,
                credentials.AgentName,
                credentials.PairedAtUtc);
            var configurationBytes = System.Text.Encoding.UTF8.GetBytes(
                JsonSerializer.Serialize(stored, JsonOptions));
            var tokenBytes = System.Text.Encoding.UTF8.GetBytes(credentials.Token);

            try
            {
                WriteAtomically(_configurationPath, configurationBytes);
                WriteAtomically(_tokenPath, _protector.Protect(tokenBytes));
            }
            finally
            {
                Array.Clear(tokenBytes);
            }
        }
    }

    public void Clear()
    {
        lock (_sync)
        {
            DeleteIfExists(_configurationPath);
            DeleteIfExists(_tokenPath);
        }
    }

    private static void WriteAtomically(string path, byte[] contents)
    {
        var temporaryPath = path + ".tmp";
        File.WriteAllBytes(temporaryPath, contents);
        File.Move(temporaryPath, path, true);
    }

    private static void DeleteIfExists(string path)
    {
        if (File.Exists(path))
        {
            File.Delete(path);
        }
    }

    private sealed record StoredConfiguration(
        string ServerUrl,
        string AgentId,
        string AgentName,
        DateTimeOffset PairedAtUtc);
}

