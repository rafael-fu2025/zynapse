# =====================================================================
# SYNAPSE — dev-down.ps1
#
# Stops whatever `dev-up.ps1` started. Reads the marker file
# `scripts/.dev-pids.json` and terminates each tracked PID.
#
# Safety:
#   - We never blanket-kill all `php.exe` / `node.exe` / `mysqld.exe`
#     on the box — only the specific PIDs that dev-up recorded.
#   - MariaDB is NOT stopped by default (you usually want the DB up
#     across dev sessions). Pass `-StopDb` to also stop mysqld.
#   - If the marker file is missing (e.g. dev-up.ps1 was killed by
#     Task Manager), the script falls back to "no PIDs to stop" and
#     leaves anything else alone.
#
# Usage:
#   pwsh ./scripts/dev-down.ps1            # stop backend + frontend only
#   pwsh ./scripts/dev-down.ps1 -StopDb    # also stop MariaDB
#   pwsh ./scripts/dev-down.ps1 -Force     # kill even if marker is stale
# =====================================================================

[CmdletBinding()]
param(
    [switch]$StopDb,
    [switch]$Force
)

$ErrorActionPreference = 'Continue'  # we want to keep stopping things even if one fails

$ScriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$MarkerFile = Join-Path $ScriptDir '.dev-pids.json'

function Write-Step($msg) { Write-Host "`n=== $msg ===" -ForegroundColor Cyan }
function Write-Ok($msg)   { Write-Host "  OK  $msg" -ForegroundColor Green }
function Write-Warn($msg) { Write-Host "  !!  $msg" -ForegroundColor Yellow }
function Write-Err($msg)  { Write-Host "  XX  $msg" -ForegroundColor Red }

function Stop-Tracked($name, $pid) {
    if (-not $pid) { return }
    $proc = Get-Process -Id $pid -ErrorAction SilentlyContinue
    if (-not $proc) {
        Write-Warn "$name PID $pid not found (already gone)."
        return
    }
    try {
        Write-Host "  -> Stopping $name (PID $pid)..." -NoNewline
        Stop-Process -Id $pid -Force -ErrorAction Stop
        Write-Host " done." -ForegroundColor Green
    } catch {
        Write-Err "Failed to stop $name PID $pid : $_"
    }
}

if (-not (Test-Path $MarkerFile)) {
    if (-not $Force) {
        Write-Warn "No marker file at $MarkerFile"
        Write-Warn "Nothing to stop. (Use -Force to clean up orphaned processes by name.)"
        exit 0
    } else {
        Write-Warn "Marker missing — falling back to name-based kill."
        Get-Process -Name php,node,mysqld -ErrorAction SilentlyContinue |
            ForEach-Object { Stop-Process -Id $_.Id -Force -ErrorAction SilentlyContinue }
        Write-Ok "Force-killed any orphaned php/node/mysqld."
        exit 0
    }
}

$marker = Get-Content $MarkerFile -Raw | ConvertFrom-Json

Write-Step "Stopping dev services (started $($marker.started_at))"

# Backend job (the Start-Job host running `spark serve`).
if ($marker.backend_job_name) {
    $job = Get-Job -Name $marker.backend_job_name -ErrorAction SilentlyContinue
    if ($job) {
        Write-Host "  -> Stopping backend job '$($marker.backend_job_name)'..." -NoNewline
        Stop-Job $job -PassThru | Remove-Job -Force
        Write-Host " done." -ForegroundColor Green
    }
}
Stop-Tracked 'backend (php)'   $marker.backend_pid
Stop-Tracked 'frontend (npm)'  $marker.frontend_pid

if ($StopDb) {
    Stop-Tracked 'mariadb (mysqld)' $marker.db_pid
} else {
    $running = Get-Process -Name mysqld -ErrorAction SilentlyContinue
    if ($running) {
        Write-Ok "MariaDB left running (PID $(($running | Select-Object -First 1).Id)). Pass -StopDb to also kill it."
    }
}

# Remove the marker so the next dev-up.ps1 starts fresh.
Remove-Item -Path $MarkerFile -Force -ErrorAction SilentlyContinue
Write-Ok "Marker file removed."

Write-Step "Done."
