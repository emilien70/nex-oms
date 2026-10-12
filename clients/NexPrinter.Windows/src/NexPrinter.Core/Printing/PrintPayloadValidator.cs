using System.Security.Cryptography;
using NexPrinter.Core.Api;

namespace NexPrinter.Core.Printing;

public static class PrintPayloadValidator
{
    public const int MaximumPayloadSize = 25 * 1024 * 1024;

    public static void ValidateMetadata(PrintJobDescriptor job)
    {
        ArgumentNullException.ThrowIfNull(job);

        if (string.IsNullOrWhiteSpace(job.Id)
            || string.IsNullOrWhiteSpace(job.PrinterSystemName)
            || string.IsNullOrWhiteSpace(job.Filename))
        {
            throw new PrintJobException("invalid_job_metadata", "Zadanie wydruku zawiera niepełne dane.");
        }

        if (!string.Equals(job.ContentType, "application/pdf", StringComparison.OrdinalIgnoreCase))
        {
            throw new PrintJobException("invalid_content_type", "Zadanie nie zawiera dokumentu PDF.");
        }

        if (job.Size <= 0 || job.Size > MaximumPayloadSize)
        {
            throw new PrintJobException("invalid_payload_size", "Rozmiar dokumentu PDF jest nieprawidłowy.");
        }

        if (job.Copies is < 1 or > 99)
        {
            throw new PrintJobException("invalid_copy_count", "Liczba kopii zadania jest nieprawidłowa.");
        }

        if (job.Sha256.Length != 64 || !job.Sha256.All(Uri.IsHexDigit))
        {
            throw new PrintJobException("invalid_payload_hash", "Suma kontrolna dokumentu PDF jest nieprawidłowa.");
        }
    }

    public static void ValidateContents(PrintJobDescriptor job, byte[] contents)
    {
        ArgumentNullException.ThrowIfNull(contents);
        ValidateMetadata(job);

        if (contents.LongLength != job.Size)
        {
            throw new PrintJobException("payload_size_mismatch", "Rozmiar pobranego PDF nie zgadza się z zadaniem.");
        }

        if (contents.Length < 5
            || contents[0] != (byte)'%'
            || contents[1] != (byte)'P'
            || contents[2] != (byte)'D'
            || contents[3] != (byte)'F'
            || contents[4] != (byte)'-')
        {
            throw new PrintJobException("invalid_pdf_header", "Pobrany plik nie ma prawidłowego nagłówka PDF.");
        }

        var actualHash = Convert.ToHexStringLower(SHA256.HashData(contents));
        if (!string.Equals(actualHash, job.Sha256, StringComparison.OrdinalIgnoreCase))
        {
            throw new PrintJobException("payload_hash_mismatch", "Suma kontrolna pobranego PDF jest nieprawidłowa.");
        }
    }
}
