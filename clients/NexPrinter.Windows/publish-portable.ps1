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

$artifactsRoot = [System.IO.Path]::GetFullPath((Join-Path $clientRoot 'artifacts'))
$stagingRoot = [System.IO.Path]::GetFullPath((Join-Path $artifactsRoot "NEX-Printer-$version-win-x64"))
$applicationDirectory = Join-Path $stagingRoot 'NEX Printer'
$zipPath = [System.IO.Path]::GetFullPath((Join-Path $artifactsRoot "NEX-Printer-$version-win-x64.zip"))
$checksumPath = "$zipPath.sha256"

foreach ($target in @($stagingRoot, $zipPath, $checksumPath)) {
    $resolvedTarget = [System.IO.Path]::GetFullPath($target)
    $artifactPrefix = $artifactsRoot.TrimEnd([System.IO.Path]::DirectorySeparatorChar) + [System.IO.Path]::DirectorySeparatorChar
    if (-not $resolvedTarget.StartsWith($artifactPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Odmowa usuniecia sciezki spoza katalogu artifacts: $resolvedTarget"
    }

    if (Test-Path -LiteralPath $resolvedTarget) {
        Remove-Item -LiteralPath $resolvedTarget -Recurse -Force
    }
}

New-Item -ItemType Directory -Path $applicationDirectory -Force | Out-Null

$testProject = Join-Path $clientRoot 'tests\NexPrinter.Core.Tests\NexPrinter.Core.Tests.csproj'
$applicationProject = Join-Path $clientRoot 'src\NexPrinter.App\NexPrinter.App.csproj'

& dotnet run --project $testProject --configuration Release
if ($LASTEXITCODE -ne 0) {
    throw 'Testy NEX Printer nie powiodly sie.'
}

& dotnet publish $applicationProject `
    --configuration Release `
    --runtime win-x64 `
    --self-contained true `
    --output $applicationDirectory `
    -p:PublishProfile=PortableWinX64
if ($LASTEXITCODE -ne 0) {
    throw 'Publikacja NEX Printer nie powiodla sie.'
}

Copy-Item -LiteralPath (Join-Path $clientRoot 'INSTRUKCJA-PORTABLE.txt') -Destination $stagingRoot
Set-Content -LiteralPath (Join-Path $stagingRoot 'VERSION.txt') -Value $version -Encoding ascii

Compress-Archive -Path (Join-Path $stagingRoot '*') -DestinationPath $zipPath -CompressionLevel Optimal
$hash = Get-FileHash -LiteralPath $zipPath -Algorithm SHA256
Set-Content `
    -LiteralPath $checksumPath `
    -Value "$($hash.Hash.ToLowerInvariant())  $([System.IO.Path]::GetFileName($zipPath))" `
    -Encoding ascii

Write-Host "Utworzono: $zipPath"
Write-Host "SHA-256: $($hash.Hash.ToLowerInvariant())"
