namespace NexPrinter.Core.Synchronization;

public sealed class SynchronizationOptions
{
    public TimeSpan HeartbeatInterval { get; init; } = TimeSpan.FromSeconds(30);

    public TimeSpan PrinterSynchronizationInterval { get; init; } = TimeSpan.FromSeconds(60);

    public IReadOnlyList<TimeSpan> RetryDelays { get; init; } =
    [
        TimeSpan.FromSeconds(2),
        TimeSpan.FromSeconds(5),
        TimeSpan.FromSeconds(15),
        TimeSpan.FromSeconds(30),
        TimeSpan.FromSeconds(60),
    ];
}

