namespace NexPrinter.Core.Configuration;

public interface IAutostartService
{
    bool IsEnabled();

    void SetEnabled(bool enabled);

    void RepairExecutablePathIfEnabled();
}
