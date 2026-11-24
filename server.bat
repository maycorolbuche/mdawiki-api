@echo off
setlocal
cd /d "%~dp0"

echo Iniciando MDAWiki em http://localhost:8008
call npx wrangler dev --port 8008
if errorlevel 1 pause