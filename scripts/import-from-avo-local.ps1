# Local AVO student import (uses portable PHP from start-local.ps1)
#   .\scripts\import-from-avo-local.ps1
#   .\scripts\import-from-avo-local.ps1 -Apply
#   .\scripts\import-from-avo-local.ps1 -Apply -Fast

param(
    [switch]$Apply,
    [switch]$Fast,
    [string]$Before = '2026-08-15'
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
$PhpExe = Join-Path $Root '.tools\php\php.exe'

if (-not (Test-Path $PhpExe)) {
    $systemPhp = Get-Command php -ErrorAction SilentlyContinue
    if ($systemPhp) {
        $PhpExe = $systemPhp.Source
    } else {
        Write-Host "Portable PHP not found. Run .\start-local.ps1 -SetupOnly once to download .tools\php" -ForegroundColor Yellow
        exit 1
    }
}

$localConfig = Join-Path $Root 'config\config.local.php'
if (-not (Test-Path $localConfig)) {
    Write-Host @"

AVO keys: copy config\config.local.php.example → config\config.local.php
and paste api_key_get / api_key_set (same as prod GitHub Secrets).

"@ -ForegroundColor Yellow
    exit 1
}

Push-Location $Root
try {
    & $PhpExe scripts\migrate.php | Out-Null
    $args = @('scripts\import-students-from-avo.php', "--before=$Before")
    if ($Apply) { $args += '--apply' } else { $args += '--dry-run' }
    if ($Fast) { $args += '--fast' }
    & $PhpExe @args
} finally {
    Pop-Location
}
