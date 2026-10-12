namespace NexPrinter.Core.Configuration;

public interface IDataProtector
{
    byte[] Protect(byte[] plainText);

    byte[] Unprotect(byte[] cipherText);
}

