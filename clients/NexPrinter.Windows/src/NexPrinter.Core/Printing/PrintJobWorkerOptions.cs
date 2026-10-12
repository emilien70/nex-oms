namespace NexPrinter.Core.Printing;

public sealed class PrintJobWorkerOptions
{
    public TimeSpan PollInterval { get; init; } = TimeSpan.FromSeconds(2);
}
