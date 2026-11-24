<#
.SYNOPSIS
    Menyalakan SatSet untuk hari demo: server web, scheduler, WhatsApp gateway (kalau aktif), dan tunnel publik.

.DESCRIPTION
    Setiap bagian berjalan di jendela PowerShell sendiri supaya log mudah dibaca. Tutup jendelanya untuk berhenti.
    Tunnel (cloudflared) dipakai agar link tiket dan QR bisa dibuka dari HP di luar Wi-Fi laptop.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\start.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\start.ps1 -NoTunnel
#>
[CmdletBinding()]
param(
    [int]$Port = 8000,
    [switch]$NoTunnel,
    [switch]$NoWhatsApp
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
$EnvPath = Join-Path $Root '.env'
$MetricsPort = 20241

function Write-Step([string]$Text) { Write-Host "`n==> $Text" -ForegroundColor Cyan }
function Write-Ok([string]$Text) { Write-Host "    OK  $Text" -ForegroundColor Green }
function Write-Note([string]$Text) { Write-Host "    !!  $Text" -ForegroundColor Yellow }
function Stop-Start([string]$Text) { Write-Host "`nGAGAL: $Text" -ForegroundColor Red; exit 1 }

function Get-EnvValue([string]$Path, [string]$Key) {
    if (-not (Test-Path $Path)) { return $null }
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        if ($line -match ('^' + [regex]::Escape($Key) + '=(.*)$')) { return $Matches[1].Trim().Trim('"') }
    }
    return $null
}

function Test-PortListening([int]$Number) {
    [bool](Get-NetTCPConnection -LocalPort $Number -State Listen -ErrorAction SilentlyContinue)
}

function Open-Window([string]$Title, [string]$Command) {
    Start-Process powershell -WorkingDirectory $Root -ArgumentList @(
        '-NoExit', '-Command', "`$host.UI.RawUI.WindowTitle = '$Title'; $Command"
    )
}

# --- 1. Persiapan --------------------------------------------------------------------
Write-Step 'Memeriksa persiapan'
if (-not (Test-Path $EnvPath)) { Stop-Start 'File .env belum ada. Jalankan scripts\setup.ps1 dulu.' }
if (Test-PortListening $Port) {
    Stop-Start "Port $Port sudah dipakai (misalnya satset:lan atau server lain). Hentikan dulu, atau pakai -Port lain."
}
Write-Ok "Port $Port kosong"

$whatsAppOn = (Get-EnvValue $EnvPath 'WHATSAPP_ENABLED') -eq 'true' -and -not $NoWhatsApp
$cloudflared = $null
if (-not $NoTunnel) {
    $cloudflared = (Get-Command cloudflared -ErrorAction SilentlyContinue).Source
    if (-not $cloudflared -and (Test-Path 'C:\Program Files (x86)\cloudflared\cloudflared.exe')) {
        $cloudflared = 'C:\Program Files (x86)\cloudflared\cloudflared.exe'
    }
    if (-not $cloudflared) { Write-Note 'cloudflared tidak ditemukan. Jalan tanpa tunnel: link hanya bisa dibuka di Wi-Fi yang sama.' }
}

# --- 2. Jendela-jendela --------------------------------------------------------------
Write-Step 'Menyalakan server web'
Open-Window 'SatSet web' "php artisan serve --host=127.0.0.1 --port=$Port"

Write-Step 'Menyalakan scheduler (pemantauan dan pengiriman ulang pesan)'
Open-Window 'SatSet scheduler' 'php artisan schedule:work'

if ($whatsAppOn) {
    Write-Step 'Menyalakan WhatsApp gateway'
    Open-Window 'SatSet wa-gateway' 'npm start'
    Write-Ok 'Jendela wa-gateway dibuka. Status sambungan ada di Admin -> Hubungkan WhatsApp.'
} else {
    Write-Note 'WhatsApp tidak aktif (WHATSAPP_ENABLED bukan true, atau -NoWhatsApp). Gateway tidak dinyalakan.'
}

# --- 3. Tunnel publik ----------------------------------------------------------------
if ($cloudflared) {
    if (Test-PortListening $MetricsPort) {
        Write-Note "Port metrics $MetricsPort sudah dipakai, kemungkinan tunnel lain sedang berjalan. Hentikan dulu jika ingin tunnel baru."
    } else {
        Write-Step 'Menyalakan tunnel publik (cloudflared)'
        Open-Window 'SatSet tunnel' "& '$cloudflared' tunnel --url http://127.0.0.1:$Port --metrics 127.0.0.1:$MetricsPort"
        $hostname = $null
        for ($i = 0; $i -lt 30 -and -not $hostname; $i++) {
            Start-Sleep -Seconds 1
            try {
                $hostname = (Invoke-RestMethod -Uri "http://127.0.0.1:$MetricsPort/quicktunnel" -TimeoutSec 2).hostname
            } catch {
                $hostname = $null
            }
        }
        if ($hostname) {
            Write-Ok "Alamat publik: https://$hostname"
        } else {
            Write-Note 'Tunnel belum melapor dalam 30 detik. Cek jendela "SatSet tunnel".'
        }
    }
}

# --- 4. Pemeriksaan singkat ----------------------------------------------------------
Write-Step 'Memeriksa server web'
$ready = $false
for ($i = 0; $i -lt 20 -and -not $ready; $i++) {
    Start-Sleep -Seconds 1
    try {
        $ready = (Invoke-WebRequest -Uri "http://127.0.0.1:$Port/" -UseBasicParsing -TimeoutSec 2).StatusCode -eq 200
    } catch {
        $ready = $false
    }
}
if ($ready) { Write-Ok "Server web hidup di http://127.0.0.1:$Port" } else { Write-Note 'Server web belum menjawab. Cek jendela "SatSet web".' }

Write-Host "`nSiap. Buka:" -ForegroundColor Green
Write-Host "  Admin      : http://localhost:$Port/admin"
Write-Host '  QR outlet  : Admin -> QR Code (cetak setelah WhatsApp tersambung)'
Write-Host '  Jangan tekan "Putuskan nomor" saat demo.'
