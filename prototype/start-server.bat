@echo off
cd /d "%~dp0"
echo WWM Cabinet prototype — local server
echo.
echo Open in browser: http://localhost:8080/
echo Press Ctrl+C to stop.
echo.
python -m http.server 8080
if errorlevel 1 (
  echo.
  echo Python not found. Install Python from https://www.python.org/downloads/
  echo Or double-click start.bat to open index.html directly.
  pause
)
