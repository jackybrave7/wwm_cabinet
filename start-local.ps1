# WWM Cabinet — local PHP dev server (Windows)
# Usage: .\start-local.ps1
#        .\start-local.ps1 -Port 8080 -Paid

param(
    [int]$Port = 8080,
    [switch]$Paid,
    [switch]$SetupOnly
)

$ErrorActionPreference = "Stop"
$Root = $PSScriptRoot
$Tools = Join-Path $Root ".tools"
$PhpDir = Join-Path $Tools "php"
$PhpExe = Join-Path $PhpDir "php.exe"
$PhpZip = Join-Path $Tools "php.zip"
$PhpUrl = "https://windows.php.net/downloads/releases/archives/php-8.3.21-nts-Win32-vs16-x64.zip"

function Write-Step([string]$Message) {
    Write-Host "`n>> $Message" -ForegroundColor Cyan
}

function Stop-PortListeners([int]$ListenPort) {
    $pids = Get-NetTCPConnection -LocalPort $ListenPort -State Listen -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty OwningProcess -Unique
    foreach ($procId in $pids) {
        if ($procId -and $procId -ne 0) {
            Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue
        }
    }
}

function Ensure-Php {
    if (Test-Path $PhpExe) {
        return $PhpExe
    }

    $systemPhp = Get-Command php -ErrorAction SilentlyContinue
    if ($systemPhp) {
        return $systemPhp.Source
    }

    Write-Step "Downloading portable PHP 8.3.21..."
    New-Item -ItemType Directory -Force -Path $PhpDir | Out-Null
    curl.exe -fsSL $PhpUrl -o $PhpZip
    Expand-Archive -Path $PhpZip -DestinationPath $PhpDir -Force
    Remove-Item $PhpZip -Force

    if (-not (Test-Path $PhpExe)) {
        throw "PHP was not extracted to $PhpExe"
    }

    return $PhpExe
}

$php = Ensure-Php
Write-Step "Using PHP: $php"
& $php -v | Select-Object -First 1

Write-Step "Preparing database..."
Push-Location $Root
try {
    & $php scripts/migrate.php
    if ($Paid) {
        & $php scripts/seed-demo.php --paid
    } else {
        & $php scripts/seed-demo.php
    }
} finally {
    Pop-Location
}

if ($SetupOnly) {
    Write-Host "`nSetup complete." -ForegroundColor Green
    exit 0
}

Write-Step "Freeing port $Port..."
Stop-PortListeners -ListenPort $Port
Start-Sleep -Milliseconds 500

Write-Step "Starting server at http://localhost:$Port"
Write-Host @"

Login:  demo@wwm.test / demo-demo-demo
        student@example.com / password

Press Ctrl+C to stop.

"@ -ForegroundColor Green

Push-Location (Join-Path $Root "public")
try {
    & $php -S "localhost:$Port" router.php
} finally {
    Pop-Location
}
