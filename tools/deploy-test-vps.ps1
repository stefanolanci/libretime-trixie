# Deploy: git pull + align legacy/Python sul VPS di test (usa Plink + .ppk, niente conversione OpenSSH).
# Richiede: PuTTY Plink, chiave .ppk. Con passphrase sulla chiave usare Pageant.
param(
    [string]$PlinkPath = "C:\Program Files\PuTTY\plink.exe",
    [string]$PpkPath = "$env:USERPROFILE\.ssh\libretime-trixie-vps.ppk",
    [string]$RemoteUserHost = "root@80.211.134.95",
    [string]$RemoteRepoDir = "/root/libretime-trixie"
)

$ErrorActionPreference = "Stop"
if (-not (Test-Path -LiteralPath $PlinkPath)) {
    Write-Error "Plink non trovato: $PlinkPath (installa PuTTY o imposta -PlinkPath)."
}
if (-not (Test-Path -LiteralPath $PpkPath)) {
    Write-Error "Chiave .ppk non trovata: $PpkPath"
}

$remoteCmd = "set -e; cd `"$RemoteRepoDir`" && git pull origin main && bash tools/align-running-install-from-here.sh"
& $PlinkPath -batch -ssh -i $PpkPath $RemoteUserHost $remoteCmd
