@echo off
cd /d "%~dp0"
echo WWM Cabinet prototype
echo.
echo Opening index.html in your default browser...
start "" "%~dp0index.html"
echo.
echo If links do not work, run: python -m http.server 8080
echo Then open: http://localhost:8080/
pause
