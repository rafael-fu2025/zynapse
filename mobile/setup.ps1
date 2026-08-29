# =============================================================================
# SYNAPSE Mobile — one-time setup (Windows).
#
# This project intentionally contains NO generated android/ios/web/windows
# platform folders (they are produced by the Flutter toolchain). Run this
# script after installing the Flutter SDK:
#
#     powershell -ExecutionPolicy Bypass -File setup.ps1
#
# It will:
#   1. Verify `flutter` is on PATH.
#   2. Run `flutter create .` to generate the platform scaffolding without
#      touching lib/ or pubspec.yaml (existing files are preserved).
#   3. Run `flutter pub get` to fetch dependencies.
#   4. Patch the Android manifest for cleartext HTTP (local dev over http).
#   5. Print run instructions.
# =============================================================================
$ErrorActionPreference = 'Stop'

if (-not (Get-Command flutter -ErrorAction SilentlyContinue)) {
    Write-Error @"
Flutter SDK was not found on PATH.
Install it first (https://docs.flutter.dev/get-started/install/windows),
then add <flutter>/bin to your PATH and re-run this script.
"@
    exit 1
}

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
Push-Location $here
try {
    Write-Host "==> Generating platform scaffolding (safe: keeps lib/ + pubspec.yaml)" -ForegroundColor Cyan
    flutter create . --project-name synapse_mobile --platforms=android,ios,web,windows

    Write-Host "==> Installing dependencies" -ForegroundColor Cyan
    flutter pub get

    Write-Host "==> Enabling cleartext HTTP for local dev (Android)" -ForegroundColor Cyan
    $manifest = Join-Path $here "android\app\src\main\AndroidManifest.xml"
    if (Test-Path $manifest) {
        $content = Get-Content $manifest -Raw
        if ($content -notmatch 'usesCleartextTraffic') {
            $content = $content -replace '<application', '<application android:usesCleartextTraffic="true"'
            Set-Content -Path $manifest -Value $content -Encoding UTF8
            Write-Host "    Patched $manifest" -ForegroundColor Green
        } else {
            Write-Host "    Manifest already patched" -ForegroundColor DarkGray
        }
    } else {
        Write-Warning "Android manifest not found yet; you may need to add android:usesCleartextTraffic=\"true\" manually."
    }

    # The generated counter-app widget test references MyApp which this
    # project does not have — remove it to keep `flutter test` green.
    $defaultTest = Join-Path $here "test\widget_test.dart"
    if (Test-Path $defaultTest) {
        Remove-Item $defaultTest
        Write-Host "    Removed generated widget_test.dart (references the counter template)" -ForegroundColor DarkGray
    }

    Write-Host ""
    Write-Host "==> Setup complete." -ForegroundColor Green
    Write-Host ""
    Write-Host "Run the app:"
    Write-Host "    flutter run -d windows        # Windows desktop (backend on localhost:8090)"
    Write-Host "    flutter run -d android        # Android emulator (backend via 10.0.2.2:8090)"
    Write-Host "    flutter run -d chrome         # Web (uses localhost:8090)"
    Write-Host ""
    Write-Host "Physical Android device? Point it at your machine's LAN IP:"
    Write-Host "    flutter run -d <device> --dart-define=API_BASE_URL=http://192.168.1.x:8090/api/v1"
} finally {
    Pop-Location
}
