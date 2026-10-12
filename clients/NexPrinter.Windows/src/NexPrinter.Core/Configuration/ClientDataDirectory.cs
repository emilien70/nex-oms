namespace NexPrinter.Core.Configuration;

public sealed record ClientDataDirectoryResult(
    string Path,
    bool ImportedLegacyData);

public static class ClientDataDirectory
{
    public static ClientDataDirectoryResult Prepare(string localApplicationDataPath)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(localApplicationDataPath);

        var root = System.IO.Path.GetFullPath(localApplicationDataPath);
        var applicationDirectory = System.IO.Path.Combine(root, ClientConstants.ApplicationName);
        var legacyDirectory = System.IO.Path.Combine(root, "NEX", ClientConstants.ApplicationName);

        Directory.CreateDirectory(applicationDirectory);
        EnsureWritable(applicationDirectory);

        var importedLegacyData = Directory.Exists(legacyDirectory)
            && CopyMissingFiles(legacyDirectory, applicationDirectory);

        return new ClientDataDirectoryResult(applicationDirectory, importedLegacyData);
    }

    private static bool CopyMissingFiles(string sourceDirectory, string destinationDirectory)
    {
        var copiedAnyFile = false;

        foreach (var sourcePath in Directory.EnumerateFiles(
                     sourceDirectory,
                     "*",
                     SearchOption.AllDirectories))
        {
            var relativePath = System.IO.Path.GetRelativePath(sourceDirectory, sourcePath);
            var destinationPath = System.IO.Path.Combine(destinationDirectory, relativePath);
            if (File.Exists(destinationPath))
            {
                continue;
            }

            var destinationParent = System.IO.Path.GetDirectoryName(destinationPath)
                ?? throw new IOException("Nie można ustalić katalogu docelowego danych NEX Printer.");
            Directory.CreateDirectory(destinationParent);
            File.Copy(sourcePath, destinationPath, overwrite: false);
            copiedAnyFile = true;
        }

        return copiedAnyFile;
    }

    private static void EnsureWritable(string directory)
    {
        var probePath = System.IO.Path.Combine(directory, $".write-test-{Guid.NewGuid():N}.tmp");

        try
        {
            using var stream = new FileStream(
                probePath,
                FileMode.CreateNew,
                FileAccess.Write,
                FileShare.None,
                bufferSize: 1,
                FileOptions.WriteThrough);
            stream.WriteByte(0);
        }
        finally
        {
            if (File.Exists(probePath))
            {
                File.Delete(probePath);
            }
        }
    }
}
