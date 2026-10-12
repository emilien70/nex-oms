using NexPrinter.Core.Api;

namespace NexPrinter.Core.Printing;

public interface IPrinterCatalog
{
    IReadOnlyList<PrinterDescriptor> GetInstalledPrinters();
}

