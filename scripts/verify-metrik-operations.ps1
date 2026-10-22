[CmdletBinding()]
param(
    [switch]$SkipFullTests,
    [switch]$SkipBuild,
    [string]$OutputDirectory = (Join-Path $env:USERPROFILE 'MetrikPOS-OperationsChecks')
)

$ErrorActionPreference = 'Stop'
if (Get-Variable -Name PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue) {
    $PSNativeCommandUseErrorActionPreference = $false
}
$ProjectRoot = (Get-Location).Path
if (-not (Test-Path (Join-Path $ProjectRoot 'artisan'))) {
    throw 'Run this script from the Laravel project root (the folder containing artisan).'
}
New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$logPath = Join-Path $OutputDirectory "metrik-operations-$timestamp.log"
$results = New-Object System.Collections.Generic.List[object]

function Write-LogLine {
    param([string]$Message)
    Write-Host $Message
    Add-Content -LiteralPath $logPath -Value $Message -Encoding UTF8
}
function Invoke-NativeCheck {
    param([Parameter(Mandatory=$true)][string]$Name, [Parameter(Mandatory=$true)][scriptblock]$Command)
    Write-LogLine "`n=== $Name ==="
    $previousPreference = $ErrorActionPreference
    $passed = $false
    $exitCode = 1
    try {
        $ErrorActionPreference = 'Continue'
        $global:LASTEXITCODE = 0
        & $Command 2>&1 | ForEach-Object { Write-LogLine ("$_") }
        $exitCode = $LASTEXITCODE
        if ($null -eq $exitCode) { $exitCode = 0 }
        $passed = ($exitCode -eq 0)
    } catch {
        Write-LogLine "ERROR: $($_.Exception.Message)"
        $passed = $false
    } finally {
        $ErrorActionPreference = $previousPreference
    }
    $results.Add([pscustomobject]@{ Check = $Name; Result = if ($passed) { 'PASS' } else { "FAIL (exit $exitCode)" } }) | Out-Null
    Write-LogLine ("RESULT: " + $(if ($passed) { 'PASS' } else { "FAIL (exit $exitCode)" }))
}

Write-LogLine 'Metrik POS operations verification'
Write-LogLine "Project root: $ProjectRoot"
Write-LogLine "Started: $(Get-Date -Format o)"
Invoke-NativeCheck 'Operations monitor (strict JSON)' { & php artisan metrik:operations-check --strict --json }
Invoke-NativeCheck 'Strict application health check' { & php artisan metrik:health-check --strict }
Invoke-NativeCheck 'Latest backup integrity and freshness' { & php artisan metrik:backup-check }
Invoke-NativeCheck 'Composer production platform requirements' { & composer check-platform-reqs --no-dev }
if (-not $SkipFullTests) {
    Invoke-NativeCheck 'Full Laravel test suite' { & php artisan test }
}
if (-not $SkipBuild) {
    Invoke-NativeCheck 'Vite production build' { & npm run build }
}

Write-LogLine "`n=== SUMMARY ==="
foreach ($result in $results) { Write-LogLine ("{0,-42} {1}" -f $result.Check, $result.Result) }
$failed = @($results | Where-Object { $_.Result -ne 'PASS' })
Write-LogLine "Log: $logPath"
if ($failed.Count -gt 0) {
    Write-LogLine "Operations verification failed: $($failed.Count) check(s)."
    exit 1
}
Write-LogLine 'All selected operations checks passed.'
exit 0
