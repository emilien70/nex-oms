using System.Reflection;

namespace NexPrinter.Core;

public static class ClientConstants
{
    public const string ApplicationName = "NEX Printer";
    public const string DefaultServerUrl = "http://127.0.0.1:8000";

    public static string ClientVersion { get; } = ResolveClientVersion();

    private static string ResolveClientVersion()
    {
        var informationalVersion = typeof(ClientConstants).Assembly
            .GetCustomAttribute<AssemblyInformationalVersionAttribute>()?
            .InformationalVersion;

        return string.IsNullOrWhiteSpace(informationalVersion)
            ? "0.0.0"
            : informationalVersion.Split('+', 2)[0];
    }
}

