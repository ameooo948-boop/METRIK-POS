[CmdletBinding()]
param(
    [string]$ProjectRoot = (Get-Location).Path,
    [string]$BackupDirectory = (Join-Path $env:USERPROFILE 'MetrikPOSBackups'),
    [ValidateRange(1, 3650)]
    [int]$RetentionDays = 30
)

$ErrorActionPreference = 'Stop'
$envFile = Join-Path $ProjectRoot '.env'
if (-not (Test-Path $envFile)) { throw "Could not find .env at $envFile" }

function Get-DotEnvValue([string]$Name) {
    $line = Get-Content $envFile | Where-Object { $_ -match "^\s*(?:export\s+)?$([regex]::Escape($Name))\s*=" } | Select-Object -First 1
    if (-not $line) { return '' }

    $value = ($line -replace '^\s*(?:export\s+)?[^=]+\s*=\s*', '').Trim()
    if (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'"))) {
        $value = $value.Substring(1, $value.Length - 2)
    } else {
        # Support ordinary unquoted dotenv comments without altering a quoted value.
        $value = ($value -replace '\s+#.*$', '').Trim()
    }
    return $value
}

function Decode-UrlPart([string]$Value) {
    return [System.Uri]::UnescapeDataString($Value)
}

$connection = (Get-DotEnvValue 'DB_CONNECTION').ToLowerInvariant()
$dbName = Get-DotEnvValue 'DB_DATABASE'
$dbHost = Get-DotEnvValue 'DB_HOST'
$dbPort = Get-DotEnvValue 'DB_PORT'
$dbUser = Get-DotEnvValue 'DB_USERNAME'
$dbPassword = Get-DotEnvValue 'DB_PASSWORD'
$dbUrl = Get-DotEnvValue 'DB_URL'
if ([string]::IsNullOrWhiteSpace($dbUrl)) { $dbUrl = Get-DotEnvValue 'DATABASE_URL' }

# Laravel can derive connection details from DB_URL / DATABASE_URL. When present,
# use the URL as the source of connection settings, matching Laravel's precedence.
if (-not [string]::IsNullOrWhiteSpace($dbUrl)) {
    try {
        $parsedUrl = [System.Uri]::new($dbUrl, [System.UriKind]::Absolute)
    } catch {
        throw 'DB_URL/DATABASE_URL is not a valid absolute database URL.'
    }

    $urlScheme = $parsedUrl.Scheme.ToLowerInvariant()
    if ($urlScheme -notin @('mysql', 'mariadb', 'sqlite')) {
        throw "Unsupported DB_URL scheme '$urlScheme'. Supported schemes: mysql, mariadb, sqlite."
    }
    $connection = $urlScheme

    if ($connection -in @('mysql', 'mariadb')) {
        if (-not [string]::IsNullOrWhiteSpace($parsedUrl.Host)) { $dbHost = $parsedUrl.DnsSafeHost }
        if ($parsedUrl.Port -gt 0) { $dbPort = [string]$parsedUrl.Port } else { $dbPort = '3306' }

        $urlUserInfo = $parsedUrl.UserInfo
        if (-not [string]::IsNullOrWhiteSpace($urlUserInfo)) {
            $separator = $urlUserInfo.IndexOf(':')
            if ($separator -ge 0) {
                $dbUser = Decode-UrlPart($urlUserInfo.Substring(0, $separator))
                $dbPassword = Decode-UrlPart($urlUserInfo.Substring($separator + 1))
            } else {
                $dbUser = Decode-UrlPart($urlUserInfo)
                $dbPassword = ''
            }
        }

        $urlDbName = Decode-UrlPart($parsedUrl.AbsolutePath.TrimStart('/'))
        if (-not [string]::IsNullOrWhiteSpace($urlDbName)) { $dbName = $urlDbName }
    } elseif ($connection -eq 'sqlite') {
        $urlPath = Decode-UrlPart($parsedUrl.AbsolutePath)
        # A Windows absolute path in a URL commonly looks like /C:/path/to/file.
        if ($urlPath -match '^/[A-Za-z]:[\\/]') {
            $dbName = $urlPath.Substring(1)
        } elseif ($urlPath.StartsWith('/')) {
            # Prefer a project-relative path if it exists (e.g. /database/database.sqlite);
            # otherwise preserve the absolute path so the existence check explains the issue.
            $relativeCandidate = $urlPath.TrimStart('/') -replace '/', [System.IO.Path]::DirectorySeparatorChar
            $candidatePath = Join-Path $ProjectRoot $relativeCandidate
            if (Test-Path $candidatePath -PathType Leaf) { $dbName = $relativeCandidate }
            else { $dbName = $urlPath }
        } else {
            $dbName = $urlPath
        }
    }
}

# Match Laravel's default database connection when DB_CONNECTION is omitted.
# A standard fresh Laravel 12 app defaults to SQLite at database/database.sqlite.
if ([string]::IsNullOrWhiteSpace($connection) -and [string]::IsNullOrWhiteSpace($dbUrl)) {
    $connection = 'sqlite'
}

# Laravel's SQLite connection commonly uses database_path('database.sqlite') as its
# default, so DB_DATABASE may be empty even though the application uses this file.
if ([string]::IsNullOrWhiteSpace($dbName) -and $connection -eq 'sqlite') {
    $defaultSqlitePath = Join-Path $ProjectRoot 'database/database.sqlite'
    if (Test-Path $defaultSqlitePath -PathType Leaf) {
        $dbName = 'database/database.sqlite'
    }
}

if ([string]::IsNullOrWhiteSpace($connection)) {
    throw 'DB_CONNECTION is empty and no DB_URL/DATABASE_URL was provided. Laravel default SQLite was not inferred; set DB_CONNECTION explicitly.'
}
if ([string]::IsNullOrWhiteSpace($dbName)) {
    throw 'Could not determine the database name/path. For SQLite, ensure database/database.sqlite exists; for MySQL/MariaDB, set DB_DATABASE or provide DB_URL/DATABASE_URL with a database path.'
}
if ($dbName -match '[\r\n]') { throw 'Database name/path has an invalid value.' }

New-Item -ItemType Directory -Force -Path $BackupDirectory | Out-Null
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$output = $null

switch ($connection) {
    { $_ -in @('mysql', 'mariadb') } {
        if ($dbName -notmatch '^[A-Za-z0-9_$-]+$') { throw 'For safety, MySQL database names must contain only letters, numbers, underscore, dollar sign, or hyphen.' }
        if ([string]::IsNullOrWhiteSpace($dbHost)) { $dbHost = '127.0.0.1' }
        if ([string]::IsNullOrWhiteSpace($dbPort)) { $dbPort = '3306' }
        if ($dbHost -match '[\r\n]' -or $dbHost.StartsWith('-')) { throw 'DB_HOST has an invalid value.' }
        if ($dbPort -notmatch '^\d{1,5}$' -or [int]$dbPort -lt 1 -or [int]$dbPort -gt 65535) { throw 'DB_PORT must be an integer between 1 and 65535.' }
        if ([string]::IsNullOrWhiteSpace($dbUser)) { throw 'DB_USERNAME is empty in .env and DB_URL/DATABASE_URL.' }

        $dumpCommand = Get-Command 'mysqldump.exe' -ErrorAction SilentlyContinue
        if (-not $dumpCommand) { $dumpCommand = Get-Command 'mysqldump' -ErrorAction SilentlyContinue }
        if (-not $dumpCommand) { throw 'mysqldump was not found on PATH. Install MySQL client tools or add mysqldump.exe to PATH.' }

        $output = Join-Path $BackupDirectory "metrikpos-$timestamp.sql"
        $previousPassword = $env:MYSQL_PWD
        try {
            # Avoid putting the password in command-line arguments.
            $env:MYSQL_PWD = $dbPassword
            $arguments = @(
                "--host=$dbHost", "--port=$dbPort", "--user=$dbUser",
                '--single-transaction', '--quick', '--routines', '--triggers',
                '--default-character-set=utf8mb4', "--result-file=$output", $dbName
            )
            & $dumpCommand.Source @arguments
            if ($LASTEXITCODE -ne 0) { throw "mysqldump failed with exit code $LASTEXITCODE." }
        } finally {
            if ($null -eq $previousPassword) { Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue }
            else { $env:MYSQL_PWD = $previousPassword }
        }
        break
    }
    'sqlite' {
        $sqliteCommand = Get-Command 'sqlite3.exe' -ErrorAction SilentlyContinue
        if (-not $sqliteCommand) { $sqliteCommand = Get-Command 'sqlite3' -ErrorAction SilentlyContinue }
        if (-not $sqliteCommand) { throw 'sqlite3 CLI was not found on PATH. Install SQLite command-line tools to create a consistent live backup.' }
        if ($dbName -eq ':memory:') { throw 'Cannot back up an in-memory SQLite database.' }
        $dbPath = if ([System.IO.Path]::IsPathRooted($dbName)) { $dbName } else { Join-Path $ProjectRoot $dbName }
        if (-not (Test-Path $dbPath -PathType Leaf)) { throw "SQLite database file was not found: $dbPath" }
        $output = Join-Path $BackupDirectory "metrikpos-$timestamp.sqlite"
        $escapedOutput = $output.Replace("'", "''")
        & $sqliteCommand.Source $dbPath ".backup '$escapedOutput'"
        if ($LASTEXITCODE -ne 0) { throw "SQLite backup failed with exit code $LASTEXITCODE." }
        break
    }
    default { throw "Unsupported DB_CONNECTION '$connection'. Supported connections: mysql, mariadb, sqlite." }
}

if (-not $output -or -not (Test-Path $output -PathType Leaf) -or (Get-Item $output).Length -le 0) {
    throw 'Backup command did not produce a non-empty backup file.'
}
Write-Host "Backup completed: $output" -ForegroundColor Green
Write-Host ("Size: {0:N2} MiB" -f ((Get-Item $output).Length / 1MB))

$cutoff = (Get-Date).AddDays(-$RetentionDays)
Get-ChildItem -Path $BackupDirectory -File -Filter 'metrikpos-*' |
    Where-Object { $_.LastWriteTime -lt $cutoff -and $_.Extension -in @('.sql', '.sqlite') } |
    Remove-Item -Force
Write-Host "Retention cleanup completed (files older than $RetentionDays days)."
