using System.Text.Json;

namespace NexPrinter.Core.Printing;

public sealed class JsonPrintHistoryStore : IPrintHistoryStore
{
    private const int MaximumRetainedEntries = 1000;
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        WriteIndented = true,
    };

    private readonly object _sync = new();
    private readonly string _historyPath;

    public JsonPrintHistoryStore(string rootDirectory)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(rootDirectory);
        Directory.CreateDirectory(rootDirectory);
        _historyPath = Path.Combine(rootDirectory, "print-history.json");
    }

    public IReadOnlyList<PrintHistoryEntry> GetRecent(int maximumEntries)
    {
        if (maximumEntries <= 0)
        {
            throw new ArgumentOutOfRangeException(nameof(maximumEntries));
        }

        lock (_sync)
        {
            return ReadEntries()
                .OrderByDescending(entry => entry.CompletedAt)
                .Take(maximumEntries)
                .ToArray();
        }
    }

    public void Save(PrintHistoryEntry entry)
    {
        ArgumentNullException.ThrowIfNull(entry);

        lock (_sync)
        {
            var entries = ReadEntries();
            var existingIndex = entries.FindIndex(existing =>
                string.Equals(existing.JobId, entry.JobId, StringComparison.Ordinal));

            if (existingIndex >= 0)
            {
                entries[existingIndex] = entry;
            }
            else
            {
                entries.Add(entry);
            }

            var retained = entries
                .OrderByDescending(existing => existing.CompletedAt)
                .Take(MaximumRetainedEntries)
                .ToArray();
            var temporaryPath = $"{_historyPath}.tmp";
            File.WriteAllText(temporaryPath, JsonSerializer.Serialize(retained, JsonOptions));
            File.Move(temporaryPath, _historyPath, true);
        }
    }

    private List<PrintHistoryEntry> ReadEntries()
    {
        if (!File.Exists(_historyPath))
        {
            return [];
        }

        try
        {
            return JsonSerializer.Deserialize<List<PrintHistoryEntry>>(
                    File.ReadAllText(_historyPath),
                    JsonOptions)
                ?? [];
        }
        catch (JsonException)
        {
            return [];
        }
    }
}
