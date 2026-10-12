namespace NexPrinter.Core.Printing;

public interface IPrintHistoryStore
{
    IReadOnlyList<PrintHistoryEntry> GetRecent(int maximumEntries);

    void Save(PrintHistoryEntry entry);
}
