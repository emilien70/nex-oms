namespace NexPrinter.Core.Synchronization;

public sealed record ConnectionStatus(
    ConnectionState State,
    string Message,
    DateTimeOffset ChangedAt,
    DateTimeOffset? LastSuccessfulConnectionAt = null,
    int PrinterCount = 0,
    TimeSpan? RetryAfter = null);

