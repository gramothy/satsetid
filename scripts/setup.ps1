<#
.SYNOPSIS
    Menyiapkan SatSet dari nol di Windows. Aman dijalankan ulang: file yang sudah ada tidak ditimpa.

.DESCRIPTION
    1. Memeriksa PHP 8.3+, Composer, dan (kalau WhatsApp dipakai) Node.js 20.12+.
    2. composer install, lalu .env dan app key (kalau .env belum ada: SQLite, APP_DEBUG=false, queue sync).
    3. Membuat database SQLite, tabel, dan tiga outlet contoh (kalau belum ada outlet).
    4. Membuat akun pemilik (password diketik sendiri, tidak pernah ditampilkan skrip).
    5. Kalau WhatsApp dipakai: wa-gateway\.env dengan token dan secret acak, npm install, dan .env Laravel
       dicocokkan dengan nilai yang sama. Nilai rahasia tidak pernah ditampilkan.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\setup.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\setup.ps1 -WithWhatsApp -SkipOwner
#>
[CmdletBinding()]
param(
    [switch]$WithWhatsApp,
    [switch]$SkipOwner,
    [switch]$NoPrompt
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
$EnvPath = Join-Path $Root '.env'
$GatewayDir = Join-Path $Root 'wa-gateway'
$GatewayEnv = Join-Path $GatewayDir '.env'
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Write-Step([string]$Text) { Write-Host "`n==> $Text" -ForegroundColor Cyan }
function Write-Ok([string]$Text) { Write-Host "    OK  $Text" -ForegroundColor Green }
function Write-Note([string]$Text) { Write-Host "    !!  $Text" -ForegroundColor Yellow }
function Stop-Setup([string]$Text) { Write-Host "`nGAGAL: $Text" -ForegroundColor Red; exit 1 }

function Test-Tool([string]$Name) { [bool](Get-Command $Name -ErrorAction SilentlyContinue) }

function Invoke-Tool([string]$File, [string[]]$Arguments) {
    & $File @Arguments
    if ($LASTEXITCODE -ne 0) { Stop-Setup "$File $($Arguments -join ' ') berhenti dengan kode $LASTEXITCODE." }
}

function Get-EnvValue([string]$Path, [string]$Key) {
    if (-not (Test-Path $Path)) { return $null }
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        if ($line -match ('^' + [regex]::Escape($Key) + '=(.*)$')) { return $Matches[1].Trim().Trim('"') }
    }
    return $null
}

function Set-EnvValue([string]$Path, [string]$Key, [string]$Value) {
    $lines = New-Object 'System.Collections.Generic.List[string]'
    if (Test-Path $Path) { $lines.AddRange([string[]][IO.File]::ReadAllLines($Path)) }
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match ('^' + [regex]::Escape($Key) + '=')) { $lines[$i] = "$Key=$Value"; $found = $true }
    }
    if (-not $found) { $lines.Add("$Key=$Value") }
    [IO.File]::WriteAllLines($Path, [string[]]$lines.ToArray(), $Utf8NoBom)
}

function New-Secret {
    $bytes = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return ([BitConverter]::ToString($bytes) -replace '-', '').ToLower()
}

function Get-TinkerNumber([string]$Code) {
    $output = & php artisan tinker --execute=$Code 2>$null | Select-Object -Last 1
    $number = 0
    if ([int]::TryParse(("$output").Trim(), [ref]$number)) { return $number }
    return -1
}

# --- 1. Alat yang dibutuhkan -------------------------------------------------------
Write-Step 'Memeriksa alat yang dibutuhkan'
if (-not (Test-Tool 'php')) { Stop-Setup 'PHP tidak ditemukan. Pasang PHP 8.3 atau lebih baru, lalu jalankan ulang skrip ini.' }
if ((& php -r "echo PHP_VERSION_ID >= 80300 ? 'ok' : 'old';") -ne 'ok') {
    Stop-Setup ('PHP ' + (& php -r 'echo PHP_VERSION;') + ' terlalu lama. Butuh 8.3 atau lebih baru.')
}
Write-Ok ('PHP ' + (& php -r 'echo PHP_VERSION;'))
if (-not (Test-Tool 'composer')) { Stop-Setup 'Composer tidak ditemukan. Pasang dari https://getcomposer.org lalu jalankan ulang skrip ini.' }
Write-Ok 'Composer'

$useWhatsApp = [bool]$WithWhatsApp
if (-not $WithWhatsApp -and -not $NoPrompt) {
    $answer = Read-Host 'Pakai WhatsApp (Baileys)? Butuh nomor khusus dan Node.js 20.12+ [y/N]'
    $useWhatsApp = $answer -match '^(y|ya)$'
}
if ($useWhatsApp) {
    if (-not (Test-Tool 'node') -or -not (Test-Tool 'npm')) { Stop-Setup 'Node.js dan npm tidak ditemukan. Pasang Node.js 20.12 atau lebih baru.' }
    $nodeVersion = (& node -p 'process.versions.node').Trim()
    if ([version]$nodeVersion -lt [version]'20.12.0') { Stop-Setup "Node.js $nodeVersion terlalu lama. Butuh 20.12 atau lebih baru." }
    Write-Ok "Node.js $nodeVersion"
}

# --- 2. Dependency dan .env --------------------------------------------------------
Write-Step 'Menginstal dependency PHP (composer install)'
Invoke-Tool 'composer' @('install', '--no-interaction', '--no-progress', '--prefer-dist')

Write-Step 'Menyiapkan .env'
if (-not (Test-Path $EnvPath)) {
    Copy-Item (Join-Path $Root '.env.example') $EnvPath
    # Demo di laptop: SQLite (tanpa MySQL), debug mati karena aplikasi bisa dibuka dari internet, antrean langsung diproses.
    Set-EnvValue $EnvPath 'DB_CONNECTION' 'sqlite'
    Set-EnvValue $EnvPath 'DB_DATABASE' (Join-Path $Root 'database\database.sqlite')
    Set-EnvValue $EnvPath 'APP_DEBUG' 'false'
    Set-EnvValue $EnvPath 'QUEUE_CONNECTION' 'sync'
    Write-Ok '.env dibuat dari .env.example (SQLite, APP_DEBUG=false, queue sync)'
} else {
    Write-Ok '.env sudah ada, isinya tidak diubah'
}
if (-not (Get-EnvValue $EnvPath 'APP_KEY')) {
    Invoke-Tool 'php' @('artisan', 'key:generate', '--force')
    Write-Ok 'APP_KEY dibuat'
}

# --- 3. Database --------------------------------------------------------------------
Write-Step 'Menyiapkan database'
if ((Get-EnvValue $EnvPath 'DB_CONNECTION') -eq 'sqlite') {
    $dbFile = Get-EnvValue $EnvPath 'DB_DATABASE'
    if (-not $dbFile) { $dbFile = Join-Path $Root 'database\database.sqlite' }
    if (-not [IO.Path]::IsPathRooted($dbFile)) { $dbFile = Join-Path $Root $dbFile }
    if (-not (Test-Path $dbFile)) {
        New-Item -ItemType File -Path $dbFile | Out-Null
        Write-Ok "File database dibuat: $dbFile"
    }
} else {
    Write-Note 'DB_CONNECTION bukan sqlite. Pastikan database MySQL di .env sudah dibuat.'
}
Invoke-Tool 'php' @('artisan', 'migrate', '--force')
Write-Ok 'Tabel sudah siap'

if ((Get-TinkerNumber 'echo App\Models\Restaurant::count();') -eq 0) {
    Invoke-Tool 'php' @('artisan', 'db:seed', '--force')
    Write-Ok 'Tiga outlet contoh dibuat'
} else {
    Write-Ok 'Outlet sudah ada, data tidak diubah'
}

# --- 4. Akun pemilik ----------------------------------------------------------------
Write-Step 'Akun pemilik'
if ((Get-TinkerNumber "echo App\Models\User::where('role', 'owner')->count();") -eq 0 -and -not $SkipOwner) {
    if ($NoPrompt) {
        Write-Note 'Belum ada akun pemilik. Buat nanti dengan: php artisan satset:admin email@anda.com --name="Nama Anda"'
    } else {
        $email = Read-Host 'Email akun pemilik'
        $name = Read-Host 'Nama pemilik (boleh kosong)'
        if (-not $name) { $name = 'Pemilik SatSet' }
        Invoke-Tool 'php' @('artisan', 'satset:admin', $email, "--name=$name")
    }
} else {
    if ($SkipOwner) { Write-Ok 'Akun pemilik dilewati (-SkipOwner)' } else { Write-Ok 'Akun pemilik sudah ada' }
}

# --- 5. WhatsApp (opsional) ---------------------------------------------------------
if ($useWhatsApp) {
    Write-Step 'Menyiapkan wa-gateway'
    if (-not (Test-Path $GatewayEnv)) {
        $lines = @(
            'WA_GATEWAY_PORT=3100',
            "WA_GATEWAY_TOKEN=$(New-Secret)",
            'WA_WEBHOOK_URL=http://127.0.0.1:8000/webhooks/baileys',
            "WA_WEBHOOK_SECRET=$(New-Secret)",
            'WA_MIN_DELAY_MS=3000',
            'WA_MAX_DELAY_MS=8000',
            'WA_DAILY_CAP=200'
        )
        [IO.File]::WriteAllLines($GatewayEnv, [string[]]$lines, $Utf8NoBom)
        Write-Ok 'wa-gateway\.env dibuat dengan token dan secret acak (tidak ditampilkan)'
    } else {
        Write-Ok 'wa-gateway\.env sudah ada, dipakai apa adanya'
    }

    $token = Get-EnvValue $GatewayEnv 'WA_GATEWAY_TOKEN'
    $secret = Get-EnvValue $GatewayEnv 'WA_WEBHOOK_SECRET'
    if (-not $token -or -not $secret) { Stop-Setup 'wa-gateway\.env belum punya WA_GATEWAY_TOKEN dan WA_WEBHOOK_SECRET.' }

    if (-not (Test-Path (Join-Path $GatewayDir 'node_modules'))) {
        Push-Location $GatewayDir
        Invoke-Tool 'npm' @('install', '--no-fund', '--no-audit')
        Pop-Location
    }
    Write-Ok 'Dependency wa-gateway terpasang'

    # Laravel memakai nilai yang sama persis dengan gateway (disalin, tidak ditampilkan).
    Set-EnvValue $EnvPath 'WHATSAPP_ENABLED' 'true'
    Set-EnvValue $EnvPath 'WHATSAPP_PROVIDER' 'baileys'
    Set-EnvValue $EnvPath 'WHATSAPP_GATEWAY_URL' 'http://127.0.0.1:3100'
    Set-EnvValue $EnvPath 'WHATSAPP_GATEWAY_TOKEN' $token
    Set-EnvValue $EnvPath 'WHATSAPP_WEBHOOK_SECRET' $secret
    Write-Ok '.env Laravel dicocokkan dengan wa-gateway'
}

# --- 6. Selesai ---------------------------------------------------------------------
Invoke-Tool 'php' @('artisan', 'config:clear')

Write-Host "`nSetup selesai." -ForegroundColor Green
Write-Host 'Menjalankan aplikasi:  powershell -ExecutionPolicy Bypass -File scripts\start.ps1'
Write-Host 'Admin:                 http://localhost:8000/admin'
if ($useWhatsApp) {
    Write-Host 'WhatsApp:              scan QR di Admin -> Hubungkan WhatsApp setelah start.ps1 berjalan.'
}
