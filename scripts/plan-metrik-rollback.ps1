[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$TargetRef,
    [string]$OutputDirectory = (Join-Path (Get-Location).Path 'storage/app/private/release-plans')
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = (& git rev-parse --show-toplevel 2>$null)
if ($LASTEXITCODE -ne 0 -or -not $ProjectRoot) {
    throw 'Run this script from a Git checkout of Metrik POS.'
}
Set-Location $ProjectRoot

$workingTree = @(& git status --porcelain)
if ($LASTEXITCODE -ne 0) { throw 'Could not inspect Git working-tree status.' }
if ($workingTree.Count -gt 0) {
    throw 'Rollback plan refused: Git working tree is not clean. Commit or safely preserve all changes first.'
}

$currentRef = (& git rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0) { throw 'Could not resolve the current Git revision.' }
$targetCommit = (& git rev-parse --verify "$TargetRef^{commit}" 2>$null)
if ($LASTEXITCODE -ne 0 -or -not $targetCommit) { throw "Target ref '$TargetRef' does not resolve to a commit." }
$targetCommit = $targetCommit.Trim()

$changedFiles = @(& git diff --name-only $targetCommit $currentRef)
if ($LASTEXITCODE -ne 0) { throw 'Could not compare the current and target revisions.' }
$migrationFiles = @($changedFiles | Where-Object { $_ -match '(^|/)database/migrations/' })

New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$planPath = Join-Path $OutputDirectory "rollback-plan-$timestamp.json"
$plan = [ordered]@{
    generated_at = (Get-Date).ToString('o')
    project_root = $ProjectRoot
    current_commit = $currentRef
    target_commit = $targetCommit
    changed_file_count = $changedFiles.Count
    changed_files = $changedFiles
    migration_files_changed_since_target = $migrationFiles
    database_rollback_automatic = $false
    approval_required = $true
    notes = @(
        'This file is a review plan only. The script does not checkout, reset, revert, migrate, or restore data.',
        'Take and verify a fresh backup before any rollback.',
        'If migration files changed, review schema compatibility and use a tested forward-fix or an approved database restore plan.',
        'Do not restore production data without incident lead approval and an understood data-loss window.'
    )
}
$plan | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $planPath -Encoding UTF8

Write-Host "Rollback review plan written: $planPath"
Write-Host "Current commit: $currentRef"
Write-Host "Target commit:  $targetCommit"
Write-Host "Changed files:  $($changedFiles.Count)"
if ($migrationFiles.Count -gt 0) {
    Write-Warning "Database migration files changed since target: $($migrationFiles.Count). Do not perform a code-only rollback until schema compatibility is reviewed."
    $migrationFiles | ForEach-Object { Write-Host "  - $_" }
} else {
    Write-Host 'No migration-file changes detected between the target and current revisions. This does not by itself prove rollback safety.'
}
Write-Host 'No application, database, or Git state was changed.'
