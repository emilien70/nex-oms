namespace NexPrinter.Core.Api;

public sealed record ClaimedPrintJob(
    PrintJobDescriptor Job,
    string LeaseToken);

public sealed record PrintJobDescriptor(
    string Id,
    string PrinterSystemName,
    string PrinterDisplayName,
    string DocumentType,
    string DocumentNumber,
    string Filename,
    string ContentType,
    long Size,
    string Sha256,
    int Copies,
    Uri PayloadUrl);

public enum PrintJobCompletionStatus
{
    Spooled,
    Failed,
}

public sealed record PrintJobCompletion(
    PrintJobCompletionStatus Status,
    string? ErrorCode = null,
    string? ErrorMessage = null);
