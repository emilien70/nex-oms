namespace NexPrinter.Core.Configuration;

public interface IClientApplicationSettingsStore
{
    ClientApplicationSettings Load();

    void Save(ClientApplicationSettings settings);
}
