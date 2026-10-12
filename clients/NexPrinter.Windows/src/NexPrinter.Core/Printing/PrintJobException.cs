namespace NexPrinter.Core.Printing;

public sealed class PrintJobException : Exception
{
    public PrintJobException(string errorCode, string message)
        : base(message)
    {
        ErrorCode = errorCode;
    }

    public string ErrorCode { get; }
}
