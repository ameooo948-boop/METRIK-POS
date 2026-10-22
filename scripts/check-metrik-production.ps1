[CmdletBinding()]
param([string]$ProjectRoot = (Get-Location).Path)

$ErrorActionPreference = 'Stop'
$envFile = Join-Path $ProjectRoot '.env'
if (-not (Test-Path $envFile)) { throw "Could not find .env at $envFile" }

function Get-DotEnvValue([string]$Name) {
    $line = Get-Content $envFile | Where-Object { $_ -match "^\s*$([regex]::Escape($Name))\s*=" } | Select-Object -First 1
    if (-not $line) { return '' }
    $value = ($line -replace '^\s*[^=]+\s*=\s*', '').Trim()
    if (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'"))) {
        $value = $value.Substring(1, $value.Length - 2)
    }
    return $value
}

$failures = 0
$warnings = 0
function Pass([string]$Text) { Write-Host "PASS $Text" -ForegroundColor Green }
function Warn([string]$Text) { $script:warnings++; Write-Host "WARN $Text" -ForegroundColor Yellow }
function Fail([string]$Text) { $script:failures++; Write-Host "FAIL $Text" -ForegroundColor Red }

$appEnv = Get-DotEnvValue 'APP_ENV'
$appDebug = (Get-DotEnvValue 'APP_DEBUG').ToLowerInvariant()
$appKey = Get-DotEnvValue 'APP_KEY'
$appUrl = Get-DotEnvValue 'APP_URL'
$sessionSecure = (Get-DotEnvValue 'SESSION_SECURE_COOKIE').ToLowerInvariant()
$queue = Get-DotEnvValue 'QUEUE_CONNECTION'
$cache = Get-DotEnvValue 'CACHE_STORE'
if (-not $cache) { $cache = Get-DotEnvValue 'CACHE_DRIVER' }

if ($appEnv -eq 'production') { Pass 'APP_ENV=production' } else { Warn "APP_ENV is '$appEnv'; this check is not running against production settings." }
if ($appDebug -eq 'false') { Pass 'APP_DEBUG=false' } else { Fail 'APP_DEBUG must be false for production.' }
if ($appKey -match '^base64:.+') { Pass 'APP_KEY is configured (value not displayed).' } elseif ($appKey) { Pass 'APP_KEY is set (value not displayed).' } else { Fail 'APP_KEY is empty.' }
if ($appUrl -match '^https://') { Pass 'APP_URL uses HTTPS' } else { Fail 'APP_URL must use https:// in production.' }
if ($sessionSecure -eq 'true') { Pass 'SESSION_SECURE_COOKIE=true' } else { Warn 'Set SESSION_SECURE_COOKIE=true behind HTTPS, after confirming the production proxy/HTTPS configuration.' }
if ($queue -in @('database', 'redis')) { Pass "QUEUE_CONNECTION=$queue" } else { Warn "QUEUE_CONNECTION='$queue'. A persistent queue driver is recommended for production reports and notifications." }
if ($cache -in @('file', 'database', 'redis')) { Pass "Cache store configured: $cache" } else { Warn "Cache store is '$cache'; validate that it is appropriate for production." }

foreach ($relative in @('vendor/autoload.php', 'public/build/manifest.json', 'public/manifest.webmanifest', 'public/sw.js')) {
    if (Test-Path (Join-Path $ProjectRoot $relative) -PathType Leaf) { Pass "$relative exists" }
    else { Fail "$relative is missing" }
}

try {
    $gitExe = Get-Command git -ErrorAction Stop
    $ignored = & $gitExe.Source -C $ProjectRoot check-ignore .env 2>$null
    if ($LASTEXITCODE -eq 0 -and $ignored) { Pass '.env is ignored by Git' }
    else { Fail '.env is not reported as ignored by Git. Fix .gitignore before staging files.' }
} catch { Warn 'Git was not found or this folder is not a repository; verify .env is not tracked before release.' }

Write-Host ""
Write-Host "Production settings review: $failures failure(s), $warnings warning(s)."
if ($failures -gt 0) { exit 1 }
exit 0
