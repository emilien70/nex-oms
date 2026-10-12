using NexPrinter.Core.Api;

namespace NexPrinter.Core.Printing;

public enum PrintJobJournalState
{
    Claimed,
    Downloaded,
    Printing,
    Spooled,
    Failed,
    Reported,
}

public sealed record PrintJobJournal(
    ClaimedPrintJob ClaimedJob,
    PrintJobJournalState State,
    string ServerUrl,
    string AgentId,
    DateTimeOffset UpdatedAtUtc,
    PrintJobCompletion? Completion = null);
