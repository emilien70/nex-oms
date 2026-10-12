namespace NexPrinter.Core.Api;

public sealed record PrinterDescriptor(
    string SystemName,
    string DisplayName,
    bool IsDefault);

