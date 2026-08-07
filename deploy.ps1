# WWM Cabinet — deploy to production via GitHub Actions
# Usage:
#   .\deploy.ps1
#   .\deploy.ps1 -Message "Deploy elke-en course content"
#   .\deploy.ps1 -Watch

param(
    [string]$Message = '',
    [switch]$Watch
)

$ErrorActionPreference = 'Stop'
$Root = $PSScriptRoot
Set-Location $Root

function Write-Step([string]$Text) {
    Write-Host "`n>> $Text" -ForegroundColor Cyan
}

if (-not (Test-Path (Join-Path $Root '.git'))) {
    throw 'Not a git repository.'
}

$remoteUrl = (git remote get-url origin 2>$null)
if (-not $remoteUrl) {
    throw 'Git remote "origin" is not configured.'
}

Write-Step 'Staging deploy files (app, templates, course content, assets)...'
$paths = @(
    'app',
    'templates',
    'public',
    'data/courses',
    'scripts/sync-elke-kinescope.php',
    'start-local.ps1',
    'start-local.bat',
    'deploy.ps1',
    'deploy.bat'
)
git add -- $paths

Get-ChildItem -Path 'data/courses' -Filter '*.bak' -ErrorAction SilentlyContinue | ForEach-Object {
    git reset HEAD -- $_.FullName 2>$null | Out-Null
}

$pending = git diff --cached --name-only
if (-not $pending) {
    $dirty = git status --porcelain
    if ($dirty) {
        Write-Host 'No deploy paths changed. Unstaged local changes:' -ForegroundColor Yellow
        git status --short
        exit 1
    }
    Write-Host 'Nothing to deploy — working tree is clean.' -ForegroundColor Green
    exit 0
}

Write-Step 'Staged changes:'
git diff --cached --stat

if ($Message -eq '') {
    $Message = @'
Deploy course content and cabinet updates.

Includes elke-en Kinescope videos, materials lessons, lesson navigation, and local start fixes.
'@.Trim()
}

Write-Step 'Creating commit...'
git commit -m $Message

$branch = (git branch --show-current).Trim()
if ($branch -ne 'master') {
    Write-Host "Warning: current branch is '$branch', not master." -ForegroundColor Yellow
}

Write-Step 'Pushing to origin (triggers GitHub Actions deploy)...'
git push origin HEAD:master

Write-Host "`nDeploy push complete." -ForegroundColor Green
Write-Host 'GitHub Actions will FTP to Sweb after the build finishes.' -ForegroundColor DarkGray
Write-Host 'Actions: https://github.com/jackybrave7/wwm_cabinet/actions' -ForegroundColor DarkGray

if (Get-Command gh -ErrorAction SilentlyContinue) {
    Write-Step 'Latest workflow run:'
    gh run list --limit 1 --repo jackybrave7/wwm_cabinet
    if ($Watch) {
        $runId = (gh run list --limit 1 --json databaseId --jq '.[0].databaseId' --repo jackybrave7/wwm_cabinet)
        if ($runId) {
            gh run watch $runId --repo jackybrave7/wwm_cabinet
        }
    }
} elseif ($Watch) {
    Write-Host 'Install GitHub CLI (gh) to watch the workflow from the terminal.' -ForegroundColor Yellow
}
