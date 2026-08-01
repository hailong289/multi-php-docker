@echo off
REM Auto-run ensure_hosts_env, then docker compose.
REM Usage: .\scripts\compose.cmd up -d
REM
REM Uses -Command (not -File) so flags like -d are not eaten by powershell.exe as -Debug.

powershell -NoProfile -ExecutionPolicy Bypass -Command "& '%~dp0compose.ps1' %*"
exit /b %ERRORLEVEL%
