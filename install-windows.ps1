# Installs WWM Cabinet to C:\projects\wwm-cabinet (Windows)
# Run in PowerShell:
#   Set-ExecutionPolicy -Scope Process Bypass -Force
#   irm https://raw.githubusercontent.com/jackybrave7/wwm-cabinet/master/install-windows.ps1 | iex
#
# Or from an already cloned repo:
#   cd C:\projects\wwm-cabinet
#   .\install-windows.ps1

$ErrorActionPreference = "Stop"

$Target = "C:\projects\wwm-cabinet"
$Branch = "master"
$Repo = "https://github.com/jackybrave7/wwm-cabinet.git"
$LegacyRepo = "https://github.com/jackybrave7/bl-school.git"
$LegacyBranch = "cursor/wwm-cabinet-06c4"

function Write-Step($msg) {
    Write-Host "`n>> $msg" -ForegroundColor Cyan
}

Write-Step "Target folder: $Target"
New-Item -ItemType Directory -Force -Path "C:\projects" | Out-Null

$SourceDir = $null

# If script lives in repo root (public\index.php next to install script)
$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
if (Test-Path (Join-Path $ScriptRoot "public\index.php")) {
    $SourceDir = $ScriptRoot
    Write-Step "Using local copy: $SourceDir"
} else {
  $Temp = Join-Path $env:TEMP ("wwm-cabinet-" + [guid]::NewGuid().ToString("n"))
  Write-Step "Cloning $Branch from $Repo ..."
  try {
    git clone --branch $Branch --single-branch --depth 1 $Repo $Temp 2>$null
    if (Test-Path (Join-Path $Temp "public\index.php")) {
      $SourceDir = $Temp
    }
  } catch {
    Write-Host "   Standalone repo not found, trying bl-school fallback..." -ForegroundColor Yellow
  }

  if (-not $SourceDir) {
    $TempLegacy = Join-Path $env:TEMP ("bl-school-" + [guid]::NewGuid().ToString("n"))
    Write-Step "Cloning $LegacyBranch from bl-school (legacy)..."
    git clone --branch $LegacyBranch --single-branch --depth 1 $LegacyRepo $TempLegacy
    $SourceDir = Join-Path $TempLegacy "wwm-cabinet"
    if (-not (Test-Path (Join-Path $SourceDir "public\index.php"))) {
      throw "wwm-cabinet folder not found in bl-school clone"
    }
    $Temp = $TempLegacy
  }
}

Write-Step "Copying files to $Target ..."
if (Test-Path $Target) {
    Write-Host "   (merging into existing folder)" -ForegroundColor Yellow
}
New-Item -ItemType Directory -Force -Path $Target | Out-Null
Copy-Item -Path (Join-Path $SourceDir "*") -Destination $Target -Recurse -Force

# Cleanup temp clone
if ($SourceDir -like "$env:TEMP\wwm-cabinet-*" -or $SourceDir -like "$env:TEMP\bl-school-*\wwm-cabinet") {
    Remove-Item -Recurse -Force (Split-Path $SourceDir -Parent)
}

Write-Step "Done."
Write-Host @"

WWM Cabinet installed to:
  $Target

Open prototype:
  $Target\prototype\start.bat

Or in PowerShell:
  cd $Target\prototype
  .\start.bat

PHP app (later):
  copy config\config.example.php config\config.php
  php scripts\migrate.php
  php scripts\seed-demo.php
  cd public
  php -S localhost:8080

"@ -ForegroundColor Green

$index = Join-Path $Target "prototype\index.html"
if (Test-Path $index) {
    $open = Read-Host "Open prototype in browser now? [Y/n]"
    if ($open -eq "" -or $open -match "^[yY]") {
        Start-Process $index
    }
}

Set-Location $Target
Write-Host "Current directory: $(Get-Location)" -ForegroundColor DarkGray
