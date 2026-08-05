# WWM Cabinet — open prototype in browser (Windows PowerShell)
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Index = Join-Path $Root "index.html"

Write-Host "WWM Cabinet prototype" -ForegroundColor Cyan
Write-Host "Opening: $Index"
Start-Process $Index

Write-Host ""
Write-Host "Tip: for a local server run:" -ForegroundColor Yellow
Write-Host "  cd $Root"
Write-Host "  python -m http.server 8080"
Write-Host "  http://localhost:8080/"
