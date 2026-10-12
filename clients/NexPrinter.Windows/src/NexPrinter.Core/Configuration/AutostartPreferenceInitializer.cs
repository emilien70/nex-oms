namespace NexPrinter.Core.Configuration;

public static class AutostartPreferenceInitializer
{
    public static bool InitializeIfNeeded(
        IClientApplicationSettingsStore settingsStore,
        IAutostartService autostartService,
        bool? initialPreference)
    {
        ArgumentNullException.ThrowIfNull(settingsStore);
        ArgumentNullException.ThrowIfNull(autostartService);

        if (initialPreference is null)
        {
            return false;
        }

        var settings = settingsStore.Load();
        if (settings.AutostartPreferenceInitialized)
        {
            return false;
        }

        autostartService.SetEnabled(initialPreference.Value);
        settingsStore.Save(settings with { AutostartPreferenceInitialized = true });

        return true;
    }
}
