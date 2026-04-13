# Ricalcola gli MD5 in legacy/application/assets.json (cache busting per Assets::url).
# Preserva ordine e formattazione riga-per-riga. Esegui dalla root del repo:
#   .\tools\update-assets-checksums.ps1
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$Legacy = Join-Path $Root "legacy"
$AssetsPath = Join-Path $Legacy "application\assets.json"
$PublicRoot = Join-Path $Legacy "public"

if (-not (Test-Path -LiteralPath $AssetsPath)) { throw "Missing $AssetsPath" }
if (-not (Test-Path -LiteralPath $PublicRoot)) { throw "Missing $PublicRoot" }

$lines = Get-Content -LiteralPath $AssetsPath -Encoding UTF8
$updated = 0
$missing = 0
$out = New-Object System.Collections.Generic.List[string]
$re = '^(?<indent>\s*)"(?<key>[^"]+)":\s*"(?<hash>[a-f0-9]{32})"(?<comma>,)?\s*$'

foreach ($line in $lines) {
    $m = [regex]::Match($line, $re)
    if (-not $m.Success) {
        $out.Add($line)
        continue
    }
    $key = $m.Groups["key"].Value
    $oldHash = $m.Groups["hash"].Value
    $indent = $m.Groups["indent"].Value
    $comma = $m.Groups["comma"].Value
    $fpath = Join-Path $PublicRoot ($key -replace "/", [IO.Path]::DirectorySeparatorChar)
    if (-not (Test-Path -LiteralPath $fpath -PathType Leaf)) {
        Write-Warning "file missing for key '$key' -> $fpath"
        $missing++
        $out.Add($line)
        continue
    }
    $newHash = (Get-FileHash -LiteralPath $fpath -Algorithm MD5).Hash.ToLowerInvariant()
    if ($oldHash -ne $newHash) { $updated++ }
    $out.Add("${indent}`"$key`": `"$newHash`"$comma")
}

$utf8NoBom = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllLines($AssetsPath, $out.ToArray(), $utf8NoBom)

Write-Host "wrote $AssetsPath ($updated checksum(s) changed, $missing missing file(s))"
if ($missing -gt 0) { exit 2 }
exit 0
