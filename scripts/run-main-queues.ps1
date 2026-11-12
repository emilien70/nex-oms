#requires -Version 5.1

param([switch]$Describe)

$workers = @(
    [PSCustomObject]@{ Name = 'main'; Queue = 'integrations,automation,default'; Sleep = 3 },
    [PSCustomObject]@{ Name = 'shipments-actions'; Queue = 'shipments-actions'; Sleep = 1 },
    [PSCustomObject]@{ Name = 'shipments-sync'; Queue = 'shipments-sync'; Sleep = 1 },
    [PSCustomObject]@{ Name = 'orders-import'; Queue = 'orders-import'; Sleep = 1 }
)

if ($Describe) {
    $workers | ConvertTo-Json
    exit 0
}

$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$php = if ($phpCommand) { $phpCommand.Source } else { 'C:\xampp\php\php.exe' }
if (-not (Test-Path -LiteralPath $php)) {
    throw 'Nie znaleziono PHP. Sprawdz PATH lub instalacje XAMPP.'
}

$createdNew = $false
$mutex = New-Object System.Threading.Mutex($true, 'Local\NEX_OMS_MainQueues_Group', [ref]$createdNew)
if (-not $createdNew) {
    $mutex.Dispose()
    throw 'Main Queues juz dziala. Nie uruchomiono drugiej grupy workerow.'
}

$logDir = Join-Path $env:LOCALAPPDATA 'NEX-OMS\logs'
New-Item -ItemType Directory -Path $logDir -Force | Out-Null
$children = @{}

function Quote-Literal([string]$Value) {
    return "'" + $Value.Replace("'", "''") + "'"
}

function Start-Worker($Worker) {
    $log = Join-Path $logDir ('queues-' + $Worker.Name + '.log')
    $arguments = @('artisan', 'queue:work', ('--queue=' + $Worker.Queue),
        ('--sleep=' + $Worker.Sleep), '--timeout=60', '--max-time=3600')
    $argumentLiterals = ($arguments | ForEach-Object { Quote-Literal $_ }) -join ' '
    $wrapper = @"
Set-Location -LiteralPath $(Quote-Literal $project)
`$ErrorActionPreference = 'Continue'
& $(Quote-Literal $php) $argumentLiterals >> $(Quote-Literal $log) 2>&1
exit `$LASTEXITCODE
"@
    $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($wrapper))
    Write-Host ("START " + $Worker.Name + ' | ' + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'))
    return Start-Process powershell.exe -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass',
        '-EncodedCommand', $encoded) -WindowStyle Hidden -PassThru
}

try {
    while ($true) {
        foreach ($worker in $workers) {
            $child = $children[$worker.Name]
            if ($null -eq $child -or $child.HasExited) {
                if ($null -ne $child) {
                    Write-Output ("STOP " + $worker.Name + ' | exit=' + $child.ExitCode)
                    $child.Dispose()
                }
                $children[$worker.Name] = Start-Worker $worker
            }
        }
        Start-Sleep -Seconds 2
    }
}
finally {
    foreach ($child in $children.Values) {
        if ($null -ne $child -and -not $child.HasExited) {
            & taskkill.exe /PID $child.Id /T /F 2>$null | Out-Null
        }
    }
    $mutex.ReleaseMutex()
    $mutex.Dispose()
}
