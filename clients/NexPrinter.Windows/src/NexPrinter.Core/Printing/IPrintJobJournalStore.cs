namespace NexPrinter.Core.Printing;

public interface IPrintJobJournalStore
{
    PrintJobJournal? Load();

    void Save(PrintJobJournal journal);

    void SavePayload(string jobId, byte[] contents);

    byte[] ReadPayload(string jobId);

    string GetPayloadPath(string jobId);

    bool PayloadExists(string jobId);

    void Clear(PrintJobJournal journal);
}
