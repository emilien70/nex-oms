using System.Net;

namespace NexPrinter.Core.Api;

public class NexPrinterApiException : Exception
{
    public NexPrinterApiException(HttpStatusCode statusCode, string? errorCode, string message)
        : base(message)
    {
        StatusCode = statusCode;
        ErrorCode = errorCode;
    }

    public HttpStatusCode StatusCode { get; }

    public string? ErrorCode { get; }
}

public sealed class AgentUnauthorizedException : NexPrinterApiException
{
    public AgentUnauthorizedException(string message)
        : base(HttpStatusCode.Unauthorized, "unauthenticated_agent", message)
    {
    }
}

