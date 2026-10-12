namespace NexPrinter.Core.Printing;

public enum PrintHistoryResult
{
    Succeeded,
    Failed,
}

public sealed record PrintHistoryEntry(
    string JobId,
    string DocumentNumber,
    string PrinterName,
    PrintHistoryResult Result,
    DateTimeOffset CompletedAt,
    string Message,
    string? ErrorCode = null);
