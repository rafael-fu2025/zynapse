# =====================================================================
# SYNAPSE — setup.ps1
#
# One-shot bootstrap for a fresh Windows dev box. Idempotent — every
# step checks whether its artifact already exists and skips if so.
#
# What it does (in order):
#   1. Sanity checks: XAMPP present, this is a Windows host, etc.
#   2. Ensures portable PHP 8.3 is at C:\Users\udtoh_lmtzs7k\php83
#   3. Ensures Composer is on PATH
#   4. backend/.env from .env.example with random secrets
#   5. Creates synapse_zcode DB in MariaDB (if not present)
#   6. composer install (if vendor/ missing)
#   7. php spark migrate --all + PermissionsAndGroupsSeeder +
#      DevUserSeeder + SeedDemoUsersSeeder
#   8. npm install in frontend/ (if node_modules/ missing)
#
# Usage:
#   pwsh ./scripts/setup.ps1
#   pwsh ./scripts/setup.ps1 -SkipPhpDownload    # use existing php83
#   pwsh ./scripts/setup.ps1 -SkipSeeds          # migrations only
# =====================================================================

[CmdletBinding()]
param(
    [switch]$SkipPhpDownload,
    [switch]$SkipComposerInstall,
    [switch]$SkipSeeds,
    [switch]$SkipFrontend
)

$ErrorActionPreference = 'Stop'

$ScriptDir   = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot    = (Resolve-Path (Join-Path $ScriptDir '..')).Path
$BackendDir  = Join-Path $RepoRoot 'backend'
$FrontendDir = Join-Path $RepoRoot 'frontend'

$PhpDir     = 'C:\Users\udtoh_lmtzs7k\php83'
$PhpExe     = Join-Path $PhpDir 'php.exe'
$XamppDir   = 'C:\Users\udtoh_lmtzs7k\xampp'
$MariadbExe = Join-Path $XamppDir 'mysql\bin\mysql.exe'

# Colour helpers
function Write-Step($msg) { Write-Host "`n=== $msg ===" -ForegroundColor Cyan }
function Write-Ok($msg)   { Write-Host "  OK  $msg" -ForegroundColor Green }
function Write-Warn($msg) { Write-Host "  !!  $msg" -ForegroundColor Yellow }
function Write-Err($msg)  { Write-Host "  XX  $msg" -ForegroundColor Red }

function Step-Required($desc, [scriptblock]$block) {
    Write-Step $desc
    & $block
}

function Test-PhpAvailable() {
    # Use the system PHP if present, otherwise fall back to portable.
    $php = (Get-Command php -ErrorAction SilentlyContinue)
    if ($php) { return $php.Source }
    if (Test-Path $PhpExe) { return $PhpExe }
    return $null
}

# ---- 0. Pre-flight
Write-Step "Pre-flight"
if ($env:OS -ne 'Windows_NT') {
    Write-Err "This script is for Windows only. macOS/Linux users: use docker-compose."
    exit 1
}
if (-not (Test-Path $XamppDir)) {
    Write-Warn "XAMPP not found at $XamppDir — MariaDB step will fail if not addressed."
}
$phpBin = Test-PhpAvailable
if ($phpBin) {
    Write-Ok "PHP found: $phpBin"
} else {
    Write-Warn "PHP not found yet — will install portable PHP 8.3 in step 2."
}

# ---- 1. Portable PHP 8.3
Step-Required "Portable PHP 8.3" {
    if (Test-Path $PhpExe) {
        Write-Ok "Already installed at $PhpExe"
        return
    }
    if ($SkipPhpDownload) {
        Write-Err "PHP not found and -SkipPhpDownload was passed."
        exit 1
    }
    # Find the latest PHP 8.3.x TS x64 zip from windows.php.net.
    # The releases page lists builds; we hardcode the known-good URL
    # for the most recent 8.3 release at the time of writing.
    $zipUrl = 'https://windows.php.net/downloads/releases/php-8.3.29-Win32-vs16-x64.zip'
    $zipPath = Join-Path $env:TEMP 'php83.zip'
    Write-Host "  Downloading $zipUrl ..."
    Invoke-WebRequest -Uri $zipUrl -OutFile $zipPath -UseBasicParsing
    Write-Ok "Downloaded $([math]::Round((Get-Item $zipPath).Length / 1MB, 1)) MB"

    Write-Host "  Extracting to $PhpDir ..."
    New-Item -ItemType Directory -Path $PhpDir -Force | Out-Null
    Expand-Archive -Path $zipPath -DestinationPath $PhpDir -Force

    # Copy php.ini-development as a starting point.
    $devIni = Join-Path $PhpDir 'php.ini-development'
    $prodIni = Join-Path $PhpDir 'php.ini'
    if ((Test-Path $devIni) -and -not (Test-Path $prodIni)) {
        Copy-Item $devIni $prodIni
        Write-Ok "Created php.ini from php.ini-development"
    }

    # Enable extensions the CI4 backend needs.
    $iniContent = Get-Content $prodIni -Raw
    $extToEnable = @('intl','zip','mysqli','mbstring','openssl','bcmath','pdo_mysql','curl','fileinfo','dom','xml')
    foreach ($ext in $extToEnable) {
        $token = "extension=$ext"
        $enabledToken = "; extension=$ext"
        # Uncomment the line if present (any leading whitespace tolerated).
        $pattern = "^(\s*);(\s*extension=$ext\b)"
        if ($iniContent -match $pattern) {
            $iniContent = $iniContent -replace $pattern, '$1$2'
            Write-Ok "Enabled extension $ext"
        } elseif ($iniContent -notmatch "(?m)^\s*extension=$ext\b") {
            $iniContent += "`nextension=$ext`n"
            Write-Ok "Appended extension $ext"
        }
    }
    # Pin extension_dir to the absolute path.
    $extDir = Join-Path $PhpDir 'ext'
    if ($iniContent -match '(?m)^;?\s*extension_dir\s*=') {
        $iniContent = $iniContent -replace '(?m)^;?\s*extension_dir\s*=.*$', "extension_dir = `"$extDir`""
    } else {
        $iniContent += "`nextension_dir = `"$extDir`"`n"
    }
    Set-Content -Path $prodIni -Value $iniContent -Encoding UTF8
    Write-Ok "php.ini configured (extension_dir = $extDir)"

    Remove-Item $zipPath -Force
    Write-Ok "Portable PHP 8.3 installed at $PhpDir"
}

# ---- 2. Composer
Step-Required "Composer" {
    $composer = Get-Command composer -ErrorAction SilentlyContinue
    if ($composer) {
        Write-Ok "Already on PATH: $($composer.Source)"
        return
    }
    if ($SkipComposerInstall) {
        Write-Err "Composer not found and -SkipComposerInstall was passed."
        exit 1
    }
    $installDir = 'C:\Users\udtoh_lmtzs7k\composer'
    $composerPhar = Join-Path $installDir 'composer.phar'
    if (-not (Test-Path $composerPhar)) {
        Write-Host "  Downloading composer installer..."
        $installer = Join-Path $env:TEMP 'composer-setup.php'
        Invoke-WebRequest -Uri 'https://getcomposer.org/installer' -OutFile $installer -UseBasicParsing
        New-Item -ItemType Directory -Path $installDir -Force | Out-Null
        & $PhpExe $installer --install-dir=$installDir --filename=composer
        Remove-Item $installer -Force
    }
    Write-Ok "Composer at $composerPhar"
    Write-Warn "Add $installDir to your user PATH (System Properties -> Environment Variables)."
    Write-Warn "Or invoke it directly: $PhpExe $composerPhar ..."
}

# ---- 3. backend/.env
Step-Required "backend/.env" {
    $envFile = Join-Path $BackendDir '.env'
    $example = Join-Path $BackendDir '.env.example'
    if (Test-Path $envFile) {
        Write-Ok "Already exists at $envFile"
        return
    }
    if (-not (Test-Path $example)) {
        Write-Err ".env.example not found in backend/. Re-clone the repo?"
        exit 1
    }
    Copy-Item $example $envFile
    # Generate cryptographically random secrets.
    $keys = @{
        'COUNSELLING_KEY'   = (& $PhpExe -r "echo bin2hex(random_bytes(32));")
        'REFERRAL_HMAC_KEY' = (& $PhpExe -r "echo bin2hex(random_bytes(32));")
        'JWT_SECRET'        = (& $PhpExe -r "echo bin2hex(random_bytes(32));")
    }
    $content = Get-Content $envFile -Raw
    foreach ($k in $keys.Keys) {
        $content = $content -replace "(?m)^$k\s*=\s*.*$", "$k = $($keys[$k])"
    }
    Set-Content -Path $envFile -Value $content -Encoding UTF8
    Write-Ok "Generated $envFile with random secrets"
}

# ---- 4. synapse_zcode DB
Step-Required "synapse_zcode database" {
    if (-not (Test-Path $MariadbExe)) {
        Write-Err "MariaDB client not found at $MariadbExe — start XAMPP first."
        exit 1
    }
    # Ensure mysqld is up; use the helper.
    $startHelper = Join-Path $ScriptDir '_start-mariadb.ps1'
    if (Test-Path $startHelper) {
        & $startHelper | Out-Host
    }
    # Now create the DB if missing.
    $createSql = @"
CREATE DATABASE IF NOT EXISTS synapse_zcode
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"@
    $createSql | & $MariadbExe -u root
    if ($LASTEXITCODE -ne 0) {
        Write-Err "Failed to create database."
        exit 1
    }
    Write-Ok "Database synapse_zcode ready (utf8mb4_unicode_ci)."
}

# ---- 5. composer install
Step-Required "composer install (backend)" {
    if (Test-Path (Join-Path $BackendDir 'vendor')) {
        Write-Ok "vendor/ already present"
        return
    }
    Push-Location $BackendDir
    try {
        & composer install --no-interaction --prefer-dist 2>&1 | Out-Host
        if ($LASTEXITCODE -ne 0) { throw "composer install failed" }
        Write-Ok "Composer dependencies installed."
    } finally {
        Pop-Location
    }
}

# ---- 6. Migrations + seeders
Step-Required "Database migrations" {
    Push-Location $BackendDir
    try {
        & $PhpExe spark migrate --all 2>&1 | Out-Host
        if ($LASTEXITCODE -ne 0) { throw "migrate --all failed" }
        Write-Ok "All migrations applied."
    } finally {
        Pop-Location
    }
}

if (-not $SkipSeeds) {
    Step-Required "Seeders" {
        Push-Location $BackendDir
        try {
            & $PhpExe spark db:seed PermissionsAndGroupsSeeder 2>&1 | Out-Host
            if ($LASTEXITCODE -ne 0) { throw "PermissionsAndGroupsSeeder failed" }
            & $PhpExe spark db:seed DevUserSeeder               2>&1 | Out-Host
            if ($LASTEXITCODE -ne 0) { throw "DevUserSeeder failed" }
            & $PhpExe spark db:seed SeedDemoUsersSeeder        2>&1 | Out-Host
            if ($LASTEXITCODE -ne 0) { throw "SeedDemoUsersSeeder failed" }
            Write-Ok "Permissions, groups, dev user, and demo users seeded."
        } finally {
            Pop-Location
        }
    }
}

# ---- 7. npm install
if (-not $SkipFrontend) {
    Step-Required "npm install (frontend)" {
        if (Test-Path (Join-Path $FrontendDir 'node_modules')) {
            Write-Ok "node_modules/ already present"
            return
        }
        Push-Location $FrontendDir
        try {
            & npm install 2>&1 | Out-Host
            if ($LASTEXITCODE -ne 0) { throw "npm install failed" }
            Write-Ok "Frontend dependencies installed."
        } finally {
            Pop-Location
        }
    }
}

Write-Step "Bootstrap complete"
Write-Host "  Next steps:" -ForegroundColor Cyan
Write-Host "    pwsh ./scripts/dev-up.ps1     # start backend + frontend"
Write-Host "    pwsh ./scripts/dev-down.ps1   # stop them"
Write-Host ""
Write-Host "  Default admin login: admin@synapse.dev / DevPassw0rd!" -ForegroundColor Yellow
Write-Host "  Demo accounts: see docs/CREDENTIALS.md" -ForegroundColor Yellow
