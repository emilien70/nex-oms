using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using NexPrinter.Core.Configuration;

namespace NexPrinter.Core.Printing;

public sealed class SecurePrintJobJournalStore : IPrintJobJournalStore
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        WriteIndented = true,
        Converters = { new JsonStringEnumConverter() },
    };

    private readonly object _sync = new();
    private readonly IDataProtector _protector;
    private readonly string _jobsDirectory;
    private readonly string _journalPath;

    public SecurePrintJobJournalStore(string rootDirectory, IDataProtector protector)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(rootDirectory);
        _protector = protector ?? throw new ArgumentNullException(nameof(protector));
        _jobsDirectory = Path.Combine(rootDirectory, "jobs");
        _journalPath = Path.Combine(_jobsDirectory, "active-job.dat");
    }

    public PrintJobJournal? Load()
    {
        lock (_sync)
        {
            if (!File.Exists(_journalPath))
            {
                return null;
            }

            byte[]? plainText = null;
            try
            {
                plainText = _protector.Unprotect(File.ReadAllBytes(_journalPath));
                return JsonSerializer.Deserialize<PrintJobJournal>(plainText, JsonOptions)
                    ?? throw new PrintJobException(
                        "recovery_journal_invalid",
                        "Lokalny dziennik zadania wydruku jest uszkodzony.");
            }
            catch (JsonException exception)
            {
                throw new PrintJobException(
                    "recovery_journal_invalid",
                    $"Lokalny dziennik zadania wydruku jest uszkodzony: {exception.Message}");
            }
            catch (CryptographicException exception)
            {
                throw new PrintJobException(
                    "recovery_journal_unreadable",
                    $"Nie można odszyfrować lokalnego dziennika wydruku: {exception.Message}");
            }
            finally
            {
                if (plainText is not null)
                {
                    Array.Clear(plainText);
                }
            }
        }
    }

    public void Save(PrintJobJournal journal)
    {
        ArgumentNullException.ThrowIfNull(journal);

        lock (_sync)
        {
            Directory.CreateDirectory(_jobsDirectory);
            var plainText = JsonSerializer.SerializeToUtf8Bytes(journal, JsonOptions);
            try
            {
                WriteAtomically(_journalPath, _protector.Protect(plainText));
            }
            finally
            {
                Array.Clear(plainText);
            }
        }
    }

    public void SavePayload(string jobId, byte[] contents)
    {
        ArgumentNullException.ThrowIfNull(contents);

        lock (_sync)
        {
            Directory.CreateDirectory(_jobsDirectory);
            WriteAtomically(GetPayloadPath(jobId), contents);
        }
    }

    public byte[] ReadPayload(string jobId)
    {
        lock (_sync)
        {
            return File.ReadAllBytes(GetPayloadPath(jobId));
        }
    }

    public string GetPayloadPath(string jobId)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(jobId);
        var safeName = Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(jobId)));
        return Path.Combine(_jobsDirectory, safeName + ".pdf");
    }

    public bool PayloadExists(string jobId)
    {
        lock (_sync)
        {
            return File.Exists(GetPayloadPath(jobId));
        }
    }

    public void Clear(PrintJobJournal journal)
    {
        ArgumentNullException.ThrowIfNull(journal);

        lock (_sync)
        {
            DeleteIfExists(_journalPath);
            DeleteIfExists(GetPayloadPath(journal.ClaimedJob.Job.Id));
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
}
