using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Text;

namespace NexPrinter.Core.Configuration;

public sealed class WindowsDpapiProtector : IDataProtector
{
    private const int CryptProtectUiForbidden = 0x1;
    private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("NEX Printer credentials v1");

    public byte[] Protect(byte[] plainText)
    {
        ArgumentNullException.ThrowIfNull(plainText);
        return Transform(plainText, protect: true);
    }

    public byte[] Unprotect(byte[] cipherText)
    {
        ArgumentNullException.ThrowIfNull(cipherText);
        return Transform(cipherText, protect: false);
    }

    private static byte[] Transform(byte[] input, bool protect)
    {
        if (!OperatingSystem.IsWindows())
        {
            throw new PlatformNotSupportedException("Bezpieczny magazyn NEX Printer wymaga systemu Windows.");
        }

        using var inputBlob = DataBlob.FromBytes(input);
        using var entropyBlob = DataBlob.FromBytes(Entropy);
        NativeDataBlob output;

        var succeeded = protect
            ? CryptProtectData(
                ref inputBlob.Value,
                "NEX Printer token",
                ref entropyBlob.Value,
                IntPtr.Zero,
                IntPtr.Zero,
                CryptProtectUiForbidden,
                out output)
            : CryptUnprotectData(
                ref inputBlob.Value,
                IntPtr.Zero,
                ref entropyBlob.Value,
                IntPtr.Zero,
                IntPtr.Zero,
                CryptProtectUiForbidden,
                out output);

        if (!succeeded)
        {
            throw new Win32Exception(Marshal.GetLastWin32Error(), "Nie udało się zabezpieczyć danych NEX Printer.");
        }

        using (var outputBlob = DataBlob.FromLocalAllocation(output))
        {
            return outputBlob.ToArray();
        }
    }

    [DllImport("crypt32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CryptProtectData(
        ref NativeDataBlob dataIn,
        string? description,
        ref NativeDataBlob optionalEntropy,
        IntPtr reserved,
        IntPtr promptStructure,
        int flags,
        out NativeDataBlob dataOut);

    [DllImport("crypt32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CryptUnprotectData(
        ref NativeDataBlob dataIn,
        IntPtr description,
        ref NativeDataBlob optionalEntropy,
        IntPtr reserved,
        IntPtr promptStructure,
        int flags,
        out NativeDataBlob dataOut);

    [DllImport("kernel32.dll")]
    private static extern IntPtr LocalFree(IntPtr memory);

    [StructLayout(LayoutKind.Sequential)]
    private struct NativeDataBlob
    {
        public int Size;
        public IntPtr Data;
    }

    [StructLayout(LayoutKind.Sequential)]
    private sealed class DataBlob : IDisposable
    {
        public NativeDataBlob Value;
        private readonly bool _localAlloc;

        private DataBlob(NativeDataBlob value, bool localAlloc)
        {
            Value = value;
            _localAlloc = localAlloc;
        }

        public static DataBlob FromBytes(byte[] bytes)
        {
            var pointer = bytes.Length == 0 ? IntPtr.Zero : Marshal.AllocHGlobal(bytes.Length);
            if (bytes.Length > 0)
            {
                Marshal.Copy(bytes, 0, pointer, bytes.Length);
            }

            return new DataBlob(new NativeDataBlob { Size = bytes.Length, Data = pointer }, localAlloc: false);
        }

        public static DataBlob FromLocalAllocation(NativeDataBlob value) => new(value, localAlloc: true);

        public byte[] ToArray()
        {
            if (Value.Size <= 0 || Value.Data == IntPtr.Zero)
            {
                return [];
            }

            var result = new byte[Value.Size];
            Marshal.Copy(Value.Data, result, 0, Value.Size);
            return result;
        }

        public void Dispose()
        {
            if (Value.Data == IntPtr.Zero)
            {
                return;
            }

            if (_localAlloc)
            {
                LocalFree(Value.Data);
            }
            else
            {
                Marshal.FreeHGlobal(Value.Data);
            }

            Value.Data = IntPtr.Zero;
            Value.Size = 0;
        }
    }
}

