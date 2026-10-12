[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$clientRoot = [System.IO.Path]::GetFullPath($PSScriptRoot)
$propsPath = Join-Path $clientRoot 'Directory.Build.props'
[xml] $props = Get-Content -LiteralPath $propsPath
$version = [string] $props.Project.PropertyGroup.NexPrinterVersion
if ([string]::IsNullOrWhiteSpace($version)) {
    throw 'Nie mozna odczytac wersji NEX Printer.'
}

$portableScript = Join-Path $clientRoot 'publish-portable.ps1'
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $portableScript
if ($LASTEXITCODE -ne 0) {
    throw 'Przygotowanie plikow NEX Printer nie powiodlo sie.'
}

$programFilesX86 = [Environment]::GetFolderPath([Environment+SpecialFolder]::ProgramFilesX86)
$programFiles = [Environment]::GetFolderPath([Environment+SpecialFolder]::ProgramFiles)
$localPrograms = Join-Path ([Environment]::GetFolderPath([Environment+SpecialFolder]::LocalApplicationData)) 'Programs'
$isccCandidates = @(
    (Join-Path $localPrograms 'Inno Setup 7\ISCC.exe'),
    (Join-Path $programFiles 'Inno Setup 7\ISCC.exe'),
    (Join-Path $programFilesX86 'Inno Setup 7\ISCC.exe'),
    (Join-Path $localPrograms 'Inno Setup 6\ISCC.exe'),
    (Join-Path $programFilesX86 'Inno Setup 6\ISCC.exe'),
    (Join-Path $programFiles 'Inno Setup 6\ISCC.exe')
)
$isccPath = $isccCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if ([string]::IsNullOrWhiteSpace($isccPath)) {
    $isccCommand = Get-Command ISCC.exe -ErrorAction SilentlyContinue
    if ($isccCommand) {
        $isccPath = $isccCommand.Source
    }
}

if ([string]::IsNullOrWhiteSpace($isccPath)) {
    throw 'Nie znaleziono kompilatora Inno Setup (ISCC.exe). Zainstaluj Inno Setup 7 lub 6.'
}

$artifactsRoot = [System.IO.Path]::GetFullPath((Join-Path $clientRoot 'artifacts'))
$sourceDirectory = [System.IO.Path]::GetFullPath((
    Join-Path $artifactsRoot "NEX-Printer-$version-win-x64\NEX Printer"))
$installerPath = [System.IO.Path]::GetFullPath((
    Join-Path $artifactsRoot "NEX-Printer-Setup-$version-x64.exe"))
$checksumPath = "$installerPath.sha256"
$manifestPath = Join-Path $artifactsRoot 'latest.json'
$installerSource = Join-Path $clientRoot 'installer\NexPrinter.iss'

foreach ($target in @($installerPath, $checksumPath, $manifestPath)) {
    $resolvedTarget = [System.IO.Path]::GetFullPath($target)
    $artifactPrefix = $artifactsRoot.TrimEnd([System.IO.Path]::DirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
    if (-not $resolvedTarget.StartsWith($artifactPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Odmowa usuniecia sciezki spoza katalogu artifacts: $resolvedTarget"
    }

    if (Test-Path -LiteralPath $resolvedTarget) {
        Remove-Item -LiteralPath $resolvedTarget -Force
    }
}

$env:NEX_PRINTER_VERSION = $version
$env:NEX_PRINTER_SOURCE_DIR = $sourceDirectory
$env:NEX_PRINTER_OUTPUT_DIR = $artifactsRoot

& $isccPath $installerSource
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $installerPath)) {
    throw 'Budowanie instalatora NEX Printer nie powiodlo sie.'
}

$hash = Get-FileHash -LiteralPath $installerPath -Algorithm SHA256
$installerFile = Get-Item -LiteralPath $installerPath
Set-Content `
    -LiteralPath $checksumPath `
    -Value "$($hash.Hash.ToLowerInvariant())  $([System.IO.Path]::GetFileName($installerPath))" `
    -Encoding ascii

$manifest = [ordered]@{
    version = $version
    filename = $installerFile.Name
    sha256 = $hash.Hash.ToLowerInvariant()
    size = $installerFile.Length
    published_at = [DateTimeOffset]::UtcNow.ToString('o')
} | ConvertTo-Json
[System.IO.File]::WriteAllText(
    $manifestPath,
    $manifest,
    [System.Text.UTF8Encoding]::new($false))

Write-Host "Utworzono: $installerPath"
Write-Host "SHA-256: $($hash.Hash.ToLowerInvariant())"
Write-Host "Manifest wydania: $manifestPath"
