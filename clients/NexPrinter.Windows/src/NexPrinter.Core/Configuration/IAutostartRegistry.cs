namespace NexPrinter.Core.Configuration;

public interface IAutostartRegistry
{
    string? ReadCommand();

    void WriteCommand(string command);

    void DeleteCommand();
}
