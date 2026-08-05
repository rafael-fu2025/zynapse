$ErrorActionPreference = 'Stop'
$exe = 'C:\Users\udtoh_lmtzs7k\xampp\mysql\bin\mysqld.exe'
$ini = 'C:\Users\udtoh_lmtzs7k\xampp\mysql\bin\my.ini'

# Check if mysqld already running (idempotent)
$existing = Get-Process -Name mysqld -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host ("mysqld already running, PID(s): " + (($existing | Select-Object -ExpandProperty Id) -join ','))
    exit 0
}

$proc = Start-Process -FilePath $exe `
    -ArgumentList "--defaults-file=`"$ini`"","--standalone" `
    -WorkingDirectory (Split-Path $exe -Parent) `
    -PassThru `
    -WindowStyle Hidden
Write-Host "Started mysqld PID: $($proc.Id)"

# Wait up to 10s for it to bind to port 3306
$ready = $false
for ($i = 0; $i -lt 10; $i++) {
    Start-Sleep -Seconds 1
    $running = Get-Process -Id $proc.Id -ErrorAction SilentlyContinue
    if ($running) {
        # Try to TCP-connect to 127.0.0.1:3306
        $tcp = New-Object System.Net.Sockets.TcpClient
        try {
            $tcp.BeginConnect('127.0.0.1', 3306, $null, $null) | Out-Null
            $iar = $tcp.BeginConnect('127.0.0.1', 3306, $null, $null)
            $ok = $iar.AsyncWaitHandle.WaitOne(500, $false)
            if ($ok -and $tcp.Connected) {
                $tcp.Close()
                $ready = $true
                break
            }
        } catch { }
        $tcp.Close()
    }
}

if ($ready) {
    Write-Host "MariaDB is ready on 127.0.0.1:3306."
    exit 0
} else {
    Write-Host "MariaDB did not become ready within 10s."
    exit 1
}