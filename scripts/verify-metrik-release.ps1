[CmdletBinding()]
param(
    [switch]$SkipBuild,
    [switch]$SkipHealthCheck,
    [string]$OutputDirectory = (Join-Path $env:USERPROFILE "MetrikPOS-ReleaseChecks")
)

$ErrorActionPreference = "Stop"
# Some PowerShell 7 profiles enable native-command stderr promotion. Composer may
# write normal progress text to stderr; do not mistake that text for a terminating
# PowerShell error. The native process exit code is checked explicitly below.
if (Get-Variable -Name PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue) {
    $PSNativeCommandUseErrorActionPreference = $false
}
$ProjectRoot = (Get-Location).Path
$Timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null
$LogPath = Join-Path $OutputDirectory "metrik-release-$Timestamp.log"
$script:Results = New-Object System.Collections.Generic.List[object]

function Write-LogLine {
    param([string]$Message)
    # Write to the console and append/close the log file for each line. This avoids
    # Tee-Object holding the log open while later checks run on Windows.
    Write-Host $Message
    Add-Content -LiteralPath $LogPath -Value $Message -Encoding UTF8
}

function Invoke-Check {
    param(
        [Parameter(Mandatory=$true)][string]$Name,
        [Parameter(Mandatory=$true)][scriptblock]$Command
    )

    Write-LogLine ""
    Write-LogLine "=== $Name ==="
    # Windows PowerShell can promote native stderr text (including Composer's
    # normal diagnostic/progress output) to an error record. With the script-wide
    # Stop preference, that can abort the pipeline on the first line even when
    # the native command itself succeeds. Keep this check's merged output
    # non-terminating and determine success from the native exit code / explicit
    # checks inside the command block. Restore the caller's preference afterward.
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = "Continue"
        & $Command 2>&1 | ForEach-Object {
            $line = "$_"
            Write-LogLine $line
        }
        $code = $LASTEXITCODE
        if ($null -eq $code) { $code = 0 }
        $passed = ($code -eq 0)
    } catch {
        Write-LogLine "ERROR: $($_.Exception.Message)"
        $passed = $false
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    $script:Results.Add([pscustomobject]@{
        Check = $Name
        Result = if ($passed) { "PASS" } else { "FAIL" }
    }) | Out-Null

    Write-LogLine ("RESULT: " + $(if ($passed) { "PASS" } else { "FAIL" }))
}

if (-not (Test-Path (Join-Path $ProjectRoot "artisan"))) {
    throw "Run this script from the Laravel project root (the folder containing artisan)."
}
if (-not (Test-Path (Join-Path $ProjectRoot "package.json"))) {
    throw "package.json was not found in the current directory."
}

Write-LogLine "Metrik POS release validation"
Write-LogLine "Project root: $ProjectRoot"
Write-LogLine "Timestamp: $(Get-Date -Format o)"
Write-LogLine "Log file: $LogPath"
Write-LogLine "Note: this script does not print .env values or modify application data."

Invoke-Check -Name "PHP version" -Command {
    php -v
    if ($LASTEXITCODE -ne 0) { throw "php -v failed" }
}
Invoke-Check -Name "Composer dependencies" -Command {
    composer check-platform-reqs --no-dev -vvv
    $composerExitCode = $LASTEXITCODE
    if ($composerExitCode -ne 0) {
        throw "Composer check-platform-reqs exited with code $composerExitCode. Review the requirement details above; if none were printed, run 'composer check-platform-reqs --no-dev -vvv' directly in PowerShell and share its output."
    }
}
Invoke-Check -Name "Laravel configuration cache clear" -Command {
    php artisan optimize:clear
    if ($LASTEXITCODE -ne 0) { throw "optimize:clear failed" }
}
Invoke-Check -Name "Laravel test suite" -Command {
    php artisan test
    if ($LASTEXITCODE -ne 0) { throw "Laravel tests failed" }
}
Invoke-Check -Name "Vite production build" -Command {
    if ($SkipBuild) {
        Write-LogLine "Skipped by -SkipBuild."
        return
    }
    npm run build
    if ($LASTEXITCODE -ne 0) { throw "Vite build failed" }
}
Invoke-Check -Name "Metrik health check" -Command {
    if ($SkipHealthCheck) {
        Write-LogLine "Skipped by -SkipHealthCheck."
        return
    }
    php artisan metrik:health-check --strict
    if ($LASTEXITCODE -ne 0) { throw "Metrik health check failed" }
}
Invoke-Check -Name "Backup freshness and archive integrity" -Command {
    php artisan metrik:backup-check
    if ($LASTEXITCODE -ne 0) { throw "No fresh verified backup was found; create a backup and resolve the issue before release." }
}
Invoke-Check -Name "Git whitespace check" -Command {
    git diff --check
    if ($LASTEXITCODE -ne 0) { throw "git diff --check failed" }
}
Invoke-Check -Name "Git working tree status" -Command {
    git status --short
    if ($LASTEXITCODE -ne 0) { throw "git status failed" }
}
Invoke-Check -Name "Tracked environment file guard" -Command {
    $trackedEnv = @(git ls-files -- .env)
    if ($LASTEXITCODE -ne 0) {
        throw "Could not inspect the Git index."
    }
    if ($trackedEnv.Count -gt 0) {
        throw ".env is tracked by Git. Remove it from the index and rotate any exposed secrets."
    }
    Write-LogLine ".env is not tracked by Git."
}

Write-LogLine ""
Write-LogLine "=== SUMMARY ==="
$script:Results | ForEach-Object {
    $row = "{0,-32} {1}" -f $_.Check, $_.Result
    Write-LogLine $row
}
$failed = @($script:Results | Where-Object { $_.Result -eq "FAIL" }).Count
Write-LogLine ""
Write-LogLine "Checks failed: $failed"
Write-LogLine "Full log: $LogPath"

if ($failed -gt 0) {
    exit 1
}
Write-LogLine "Validation commands passed. Manual acceptance, backup restore, and staging checks are still required."
exit 0
