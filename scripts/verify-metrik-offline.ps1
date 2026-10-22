[CmdletBinding()]
param(
    [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot),
    [switch]$SkipBuild
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path (Join-Path $ProjectRoot 'artisan'))) {
    throw "Laravel project root was not found: $ProjectRoot"
}

$logDirectory = Join-Path $ProjectRoot 'storage/logs'
if (-not (Test-Path $logDirectory)) {
    New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
}
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$logFile = Join-Path $logDirectory "metrik-offline-verification-$timestamp.log"
$failed = New-Object System.Collections.Generic.List[string]

$checks = @(
    @{ Name = 'Offline catalog and synchronization routes'; Executable = 'php'; Arguments = @('artisan', 'route:list', '--path=pos/offline') },
    @{ Name = 'Hardware setup route'; Executable = 'php'; Arguments = @('artisan', 'route:list', '--path=pos/devices') },
    @{ Name = 'Offline sync integration tests'; Executable = 'php'; Arguments = @('artisan', 'test', '--filter=OfflineSaleSyncTest') },
    @{ Name = 'POS checkout and idempotency tests'; Executable = 'php'; Arguments = @('artisan', 'test', '--filter=PointOfSaleTest') },
    @{ Name = 'Hardware setup page tests'; Executable = 'php'; Arguments = @('artisan', 'test', '--filter=HardwareDevicesPageTest') },
    @{ Name = 'Composer non-dev platform requirements'; Executable = 'composer'; Arguments = @('check-platform-reqs', '--no-dev') }
)

if (-not $SkipBuild) {
    $checks += @{ Name = 'Vite production build'; Executable = 'npm'; Arguments = @('run', 'build') }
}
$checks += @{ Name = 'Strict application health check'; Executable = 'php'; Arguments = @('artisan', 'metrik:health-check', '--strict') }

"Metrik POS offline/device verification" | Tee-Object -FilePath $logFile
"Project root: $ProjectRoot" | Tee-Object -FilePath $logFile -Append
"Started: $(Get-Date -Format o)" | Tee-Object -FilePath $logFile -Append
"Note: this script does not change application data or print .env values." | Tee-Object -FilePath $logFile -Append

Push-Location $ProjectRoot
try {
    foreach ($check in $checks) {
        "`r`n=== $($check.Name) ===" | Tee-Object -FilePath $logFile -Append
        $arguments = $check.Arguments

        # Some native tools (notably composer.bat on Windows) write normal
        # progress text to stderr. With ErrorActionPreference=Stop, PowerShell
        # can promote that text to NativeCommandError and abort the verifier
        # before it can inspect the process exit code. Treat process output as
        # log output and determine pass/fail from the native exit code instead.
        $previousErrorActionPreference = $ErrorActionPreference
        $nativePreference = Get-Variable -Name PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue
        if ($null -ne $nativePreference) {
            $previousNativeCommandPreference = $nativePreference.Value
            $PSNativeCommandUseErrorActionPreference = $false
        }

        try {
            $ErrorActionPreference = 'Continue'
            & $check.Executable @arguments 2>&1 | Tee-Object -FilePath $logFile -Append
            $exitCode = $LASTEXITCODE
        }
        finally {
            $ErrorActionPreference = $previousErrorActionPreference
            if ($null -ne $nativePreference) {
                $PSNativeCommandUseErrorActionPreference = $previousNativeCommandPreference
            }
        }
        if ($exitCode -eq 0) {
            "RESULT: PASS" | Tee-Object -FilePath $logFile -Append
        }
        else {
            "RESULT: FAIL (exit $exitCode)" | Tee-Object -FilePath $logFile -Append
            $failed.Add($check.Name)
        }
    }
}
finally {
    Pop-Location
}

"`r`n=== SUMMARY ===" | Tee-Object -FilePath $logFile -Append
if ($failed.Count -eq 0) {
    'All automated offline/device checks passed.' | Tee-Object -FilePath $logFile -Append
    "Log: $logFile" | Tee-Object -FilePath $logFile -Append
    exit 0
}

foreach ($name in $failed) {
    "FAILED: $name" | Tee-Object -FilePath $logFile -Append
}
"Checks failed: $($failed.Count)" | Tee-Object -FilePath $logFile -Append
"Full log: $logFile" | Tee-Object -FilePath $logFile -Append
exit 1
