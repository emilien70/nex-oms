namespace NexPrinter.Core.Configuration;

public sealed record ClientLaunchOptions(bool StartInBackground)
{
    public static ClientLaunchOptions Parse(IEnumerable<string> arguments)
    {
        ArgumentNullException.ThrowIfNull(arguments);

        return new ClientLaunchOptions(arguments.Any(argument =>
            string.Equals(argument, "--background", StringComparison.OrdinalIgnoreCase)));
    }
}
