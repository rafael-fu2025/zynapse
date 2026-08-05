# =====================================================================
# SYNAPSE — dev-up.ps1
#
# Starts the local dev environment on Windows:
#   1. MariaDB (XAMPP) on 127.0.0.1:3306 — launched if not running
#   2. CodeIgniter 4 backend on http://127.0.0.1:8090
#   3. Vite frontend dev server on http://127.0.0.1:5173 (proxies /api -> 8090)
#
# Behaviour:
#   - Prepends the portable PHP 8.3 + XAMPP mysql\bin paths to $env:PATH
#     for THIS session only (your global PATH is untouched).
#   - Tracks every spawned process by PID in a per-session marker file
#     (`scripts/.dev-pids.json`) so `dev-down.ps1` knows exactly what to
#     stop without killing unrelated Node/PHP processes on the box.
#   - Idempotent: re-running while services are already up is a no-op
#     (the second invocation detects the marker file and exits cleanly).
#
# Usage:
#   pwsh ./scripts/dev-up.ps1
#   pwsh ./scripts/dev-up.ps1 -SkipFrontend
#   pwsh ./scripts/dev-down.ps1
# =====================================================================

[CmdletBinding()]
param(
    [switch]$SkipFrontend,
    [switch]$SkipBackend,
    [switch]$SkipDb
)

$ErrorActionPreference = 'Stop'

# ---- Resolve repo root (scripts/..) and pin working directory.
$ScriptDir   = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot    = (Resolve-Path (Join-Path $ScriptDir '..')).Path
$BackendDir  = Join-Path $RepoRoot 'backend'
$FrontendDir = Join-Path $RepoRoot 'frontend'
$MarkerFile  = Join-Path $ScriptDir '.dev-pids.json'

# ---- Tooling paths (per Phase 4 bootstrap plan).
$PhpDir      = 'C:\Users\udtoh_lmtzs7k\php83'
$PhpExe      = Join-Path $PhpDir 'php.exe'
$MysqlBinDir = 'C:\Users\udtoh_lmtzs7k\xampp\mysql\bin'

function Write-Step($msg)  { Write-Host "`n=== $msg ===" -ForegroundColor Cyan }
function Write-Ok($msg)    { Write-Host "  OK  $msg"     -ForegroundColor Green }
function Write-Warn($msg)  { Write-Host "  !!  $msg"     -ForegroundColor Yellow }
function Write-Err($msg)   { Write-Host "  XX  $msg"     -ForegroundColor Red }

# ---- Idempotency: if marker exists, services are presumed running.
if (Test-Path $MarkerFile) {
    Write-Warn "Marker file already exists: $MarkerFile"
    Write-Warn "Services appear to be up from a previous run. Run ./scripts/dev-down.ps1 first."
    exit 1
}

# ---- 0. Prepend portable PHP 8.3 + XAMPP mysql\bin to PATH (session-scoped).
$env:PATH = "$PhpDir;$MysqlBinDir;$env:PATH"
$env:Path = $env:PATH  # PowerShell is case-insensitive but explicit both helps debug

# ---- 1. Start MariaDB if not already running.
if (-not $SkipDb) {
    Write-Step "MariaDB (XAMPP)"
    $running = Get-Process -Name mysqld -ErrorAction SilentlyContinue
    if ($running) {
        Write-Ok "mysqld already running (PID(s): $(($running | ForEach-Object Id) -join ','))"
        $DbPid = ($running | Select-Object -First 1).Id
    } else {
        if (-not (Test-Path $MysqlBinDir)) {
            Write-Err "MariaDB bin dir not found: $MysqlBinDir"
            exit 1
        }
        $mysqlStart = Join-Path $ScriptDir '_start-mariadb.ps1'
        if (-not (Test-Path $mysqlStart)) {
            Write-Err "Helper script not found: $mysqlStart"
            exit 1
        }
        & $mysqlStart | Out-Host
        if ($LASTEXITCODE -ne 0) {
            Write-Err "MariaDB failed to start."
            exit 1
        }
        $running = Get-Process -Name mysqld -ErrorAction SilentlyContinue
        $DbPid = ($running | Select-Object -First 1).Id
        Write-Ok "MariaDB started (PID: $DbPid)"
    }
}

# ---- 2. Backend (CodeIgniter 4 `spark serve`).
$BackendPid = $null
if (-not $SkipBackend) {
    Write-Step "Backend (php spark serve on :8090)"
    if (-not (Test-Path $PhpExe)) {
        Write-Err "Portable PHP 8.3 not found at: $PhpExe"
        Write-Err "Run ./scripts/setup.ps1 first."
        exit 1
    }
    if (-not (Test-Path (Join-Path $BackendDir 'vendor'))) {
        Write-Err "backend/vendor missing — run `composer install` first."
        exit 1
    }
    if (-not (Test-Path (Join-Path $BackendDir '.env'))) {
        Write-Err "backend/.env missing — copy .env.example and edit secrets."
        exit 1
    }

    # `spark serve` is a blocking PHP builtin server, so we must run it
    # in a background PowerShell job. The job's PID maps to php.exe
    # because `spark` exec()s `php -S` internally.
    Push-Location $BackendDir
    try {
        $BackendJob = Start-Job -ScriptBlock {
            param($cwd, $php)
            Set-Location $cwd
            & $php spark serve --host 127.0.0.1 --port 8090 2>&1
        } -ArgumentList $BackendDir, $PhpExe
    } finally {
        Pop-Location
    }
    Start-Sleep -Seconds 2

    # Find the spawned php.exe (one is the Job host, one is the HTTP server).
    # We track the builtin server PID by inspecting netstat / Get-Process.
    $phpPids = Get-Process -Name php -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Id
    if ($phpPids) {
        $BackendPid = ($phpPids | Select-Object -First 1)
        Write-Ok "Backend launched (PID: $BackendPid). Tailing at http://127.0.0.1:8090"
    } else {
        Write-Err "Backend process not detected after launch."
        Stop-Job $BackendJob -PassThru | Remove-Job -Force
        exit 1
    }

    # Wait until the port is actually accepting connections (max 15s).
    $ready = $false
    for ($i = 0; $i -lt 15; $i++) {
        Start-Sleep -Seconds 1
        $tcp = New-Object System.Net.Sockets.TcpClient
        try {
            $iar = $tcp.BeginConnect('127.0.0.1', 8090, $null, $null)
            $ok  = $iar.AsyncWaitHandle.WaitOne(500, $false)
            if ($ok -and $tcp.Connected) { $ready = $true }
        } catch {
            # swallow: next iteration will retry
        } finally {
            $tcp.Close()
        }
        if ($ready) { break }
    }
    if (-not $ready) {
        Write-Err "Backend did not bind to :8090 within 15s."
        Stop-Job $BackendJob -PassThru | Remove-Job -Force
        exit 1
    }
    Write-Ok "Backend ready on http://127.0.0.1:8090"
}

# ---- 3. Frontend (Vite dev server).
$FrontendPid = $null
if (-not $SkipFrontend) {
    Write-Step "Frontend (vite dev on :5173)"
    if (-not (Test-Path (Join-Path $FrontendDir 'node_modules'))) {
        Write-Err "frontend/node_modules missing — run `npm install` first."
        exit 1
    }

    Push-Location $FrontendDir
    try {
        # npm wraps node — the npm.exe PID is what we record. Vite itself
        # is the child node process.
        $npmArgs = @('run', 'dev', '--', '--host', '127.0.0.1', '--port', '5173')
        $FrontendProc = Start-Process -FilePath 'npm.cmd' -ArgumentList $npmArgs -WorkingDirectory $FrontendDir -PassThru -WindowStyle Hidden
    } finally {
        Pop-Location
    }
    $FrontendPid = $FrontendProc.Id
    Write-Ok "Frontend launched (PID: $FrontendPid). Tailing at http://127.0.0.1:5173"

    # Wait until Vite is ready (max 30s — npm + optimizer can be slow).
    $ready = $false
    for ($i = 0; $i -lt 30; $i++) {
        Start-Sleep -Seconds 1
        $tcp = New-Object System.Net.Sockets.TcpClient
        try {
            $iar = $tcp.BeginConnect('127.0.0.1', 5173, $null, $null)
            $ok  = $iar.AsyncWaitHandle.WaitOne(500, $false)
            if ($ok -and $tcp.Connected) { $ready = $true }
        } catch {
            # swallow: next iteration will retry
        } finally {
            $tcp.Close()
        }
        if ($ready) { break }
    }
    if (-not $ready) {
        Write-Warn "Frontend did not bind to :5173 within 30s — Vite may still be optimising deps."
    } else {
        Write-Ok "Frontend ready on http://127.0.0.1:5173"
    }
}

# ---- 4. Write the marker file so dev-down.ps1 knows what to kill.
$marker = @{
    started_at  = (Get-Date).ToString('o')
    backend_pid = $BackendPid
    frontend_pid = $FrontendPid
    db_pid      = $DbPid
    repo_root   = $RepoRoot
    backend_job_name = if ($BackendJob) { $BackendJob.Name } else { $null }
} | ConvertTo-Json
Set-Content -Path $MarkerFile -Value $marker -Encoding UTF8

Write-Step "All up."
Write-Host "  Backend  : http://127.0.0.1:8090  (PID $BackendPid)" -ForegroundColor Green
Write-Host "  Frontend : http://127.0.0.1:5173  (PID $FrontendPid)" -ForegroundColor Green
Write-Host "  MariaDB  : 127.0.0.1:3306       (PID $DbPid)" -ForegroundColor Green
Write-Host ""
Write-Host "Press Ctrl+C to stop background services, then run:" -ForegroundColor Cyan
Write-Host "    pwsh ./scripts/dev-down.ps1" -ForegroundColor White

# ---- 5. Wait on the backend job so Ctrl+C in this shell tears it down.
if ($BackendJob) {
    try {
        # Block until the user hits Ctrl+C. Receiving Console.CancelKeyPress
        # in Start-Job's host is racy; the marker file is the real source
        # of truth for dev-down.ps1.
        Wait-Job $BackendJob | Out-Null
    } finally {
        Write-Host "Backend job exited. Run ./scripts/dev-down.ps1 to clean up." -ForegroundColor Yellow
    }
}
