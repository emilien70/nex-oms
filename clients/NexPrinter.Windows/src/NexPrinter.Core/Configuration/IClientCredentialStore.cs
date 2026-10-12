namespace NexPrinter.Core.Configuration;

public interface IClientCredentialStore
{
    ClientCredentials? Load();

    void Save(ClientCredentials credentials);

    void Clear();
}

