namespace NexPrinter.Core.Configuration;

public static class ServerAddressPolicy
{
    public static Uri Normalize(string value)
    {
        if (!Uri.TryCreate(value?.Trim(), UriKind.Absolute, out var uri))
        {
            throw new ArgumentException("Podaj prawidłowy adres serwera NEX-OMS.", nameof(value));
        }

        if (!string.IsNullOrEmpty(uri.UserInfo) || !string.IsNullOrEmpty(uri.Query) || !string.IsNullOrEmpty(uri.Fragment))
        {
            throw new ArgumentException("Adres serwera nie może zawierać danych logowania, parametrów ani fragmentu.", nameof(value));
        }

        var isHttps = string.Equals(uri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase);
        var isLocalHttp = string.Equals(uri.Scheme, Uri.UriSchemeHttp, StringComparison.OrdinalIgnoreCase)
            && uri.IsLoopback;

        if (!isHttps && !isLocalHttp)
        {
            throw new ArgumentException(
                "Połączenie HTTP jest dozwolone wyłącznie dla lokalnego NEX-OMS. Serwer zewnętrzny musi używać HTTPS.",
                nameof(value));
        }

        var builder = new UriBuilder(uri)
        {
            Path = uri.AbsolutePath.TrimEnd('/') + "/",
            Query = string.Empty,
            Fragment = string.Empty,
        };

        return builder.Uri;
    }
}

