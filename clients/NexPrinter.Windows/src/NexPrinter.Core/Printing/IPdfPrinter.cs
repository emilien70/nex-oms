namespace NexPrinter.Core.Printing;

public interface IPdfPrinter
{
    Task<PdfPrintResult> PrintAsync(
        string pdfPath,
        string printerName,
        int copies,
        CancellationToken cancellationToken);
}

public sealed record PdfPrintResult(
    bool Succeeded,
    string? ErrorCode = null,
    string? ErrorMessage = null)
{
    public static PdfPrintResult Success() => new(true);

    public static PdfPrintResult Failure(string errorCode, string errorMessage) =>
        new(false, errorCode, errorMessage);
}
