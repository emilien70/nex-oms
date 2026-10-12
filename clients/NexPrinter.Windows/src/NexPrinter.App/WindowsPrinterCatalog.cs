using System.Drawing.Printing;
using NexPrinter.Core.Api;
using NexPrinter.Core.Printing;

namespace NexPrinter.App;

public sealed class WindowsPrinterCatalog : IPrinterCatalog
{
    public IReadOnlyList<PrinterDescriptor> GetInstalledPrinters()
    {
        var defaultPrinter = new PrinterSettings().PrinterName;

        return PrinterSettings.InstalledPrinters
            .Cast<string>()
            .Where(name => !string.IsNullOrWhiteSpace(name))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .OrderBy(name => name, StringComparer.CurrentCultureIgnoreCase)
            .Select(name => new PrinterDescriptor(
                name,
                name,
                string.Equals(name, defaultPrinter, StringComparison.OrdinalIgnoreCase)))
            .ToArray();
    }
}

