$phpDir = "C:\php-8.5.1-nts-Win32-vs17-x64"
$sslDir = Join-Path $phpDir "extras\ssl"
$certPath = Join-Path $sslDir "cacert.pem"

if (!(Test-Path $sslDir)) {
    New-Item -ItemType Directory -Force -Path $sslDir | Out-Null
}

Write-Host "Téléchargement de cacert.pem..."
Invoke-WebRequest -Uri "https://curl.se/ca/cacert.pem" -OutFile $certPath

$phpIniPath = Join-Path $phpDir "php.ini"
$iniContent = Get-Content $phpIniPath

# Vérifier et mettre à jour curl.cainfo
if ($iniContent -match "^;?curl\.cainfo\s*=") {
    $iniContent = $iniContent -replace "^;?curl\.cainfo\s*=.*", "curl.cainfo = `"$certPath`""
} else {
    $iniContent += "`n[curl]`ncurl.cainfo = `"$certPath`""
}

# Vérifier et mettre à jour openssl.cafile
if ($iniContent -match "^;?openssl\.cafile\s*=") {
    $iniContent = $iniContent -replace "^;?openssl\.cafile\s*=.*", "openssl.cafile = `"$certPath`""
} else {
    $iniContent += "`n[openssl]`nopenssl.cafile = `"$certPath`""
}

Set-Content -Path $phpIniPath -Value $iniContent
Write-Host "php.ini mis à jour avec succès !"
