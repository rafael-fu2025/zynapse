$ErrorActionPreference = 'Stop'
$scripts = @(
    (Join-Path $PSScriptRoot 'dev-up.ps1')
    (Join-Path $PSScriptRoot 'dev-down.ps1')
    (Join-Path $PSScriptRoot 'setup.ps1')
)
foreach ($s in $scripts) {
    $errors = $null
    $null = [System.Management.Automation.Language.Parser]::ParseFile($s, [ref]$null, [ref]$errors)
    if ($errors) {
        Write-Host "PARSE ERRORS in $s" -ForegroundColor Red
        foreach ($e in $errors) {
            Write-Host "  L$($e.Extent.StartLineNumber) C$($e.Extent.StartColumnNumber): $($e.Message)" -ForegroundColor Red
        }
        exit 1
    } else {
        Write-Host "OK  $s" -ForegroundColor Green
    }
}
Write-Host "All scripts parse cleanly." -ForegroundColor Cyan
