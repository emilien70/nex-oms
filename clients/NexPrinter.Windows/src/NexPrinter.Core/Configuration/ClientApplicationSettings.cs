namespace NexPrinter.Core.Configuration;

public sealed record ClientApplicationSettings(
    bool ShowErrorNotifications = true,
    bool AutostartPreferenceInitialized = false);
