namespace NexPrinter.Core.Configuration;

public sealed record ClientCredentials(
    Uri ServerUrl,
    string AgentId,
    string AgentName,
    string Token,
    DateTimeOffset PairedAtUtc);

