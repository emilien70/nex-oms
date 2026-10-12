namespace NexPrinter.Core.Printing;

public enum PrintJobWorkerState
{
    Waiting,
    Idle,
    Downloading,
    Printing,
    Reporting,
    Succeeded,
    Failed,
    Offline,
}

public sealed record PrintJobWorkerStatus(
    PrintJobWorkerState State,
    string Title,
    string Message,
    DateTimeOffset ChangedAt,
    string? DocumentNumber = null,
    string? PrinterName = null);
