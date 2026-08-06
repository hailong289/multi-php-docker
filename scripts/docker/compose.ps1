#Requires -Version 5.1
<#
.SYNOPSIS
  Run ensure_hosts_env, then forward all args to docker compose.

.NOTES
  Do not invoke as: powershell -File compose.ps1 up -d
  PowerShell.exe treats bare -d as -Debug, so only "up" reaches the script and
  `docker compose up` attaches forever. Use compose.cmd instead, or:
    powershell -NoProfile -ExecutionPolicy Bypass -Command "& '.\scripts\docker\compose.ps1' up -d"

.EXAMPLE
  .\scripts\docker\compose.cmd up -d
  .\scripts\docker\compose.cmd --profile php-8.1 up -d
#>

$ErrorActionPreference = 'Stop'

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$EnsureScript = Join-Path (Split-Path -Parent $ScriptDir) 'hosts\ensure_hosts_env.ps1'

if (-not (Test-Path -LiteralPath $EnsureScript)) {
    throw "Missing $EnsureScript"
}

& $EnsureScript

if ($args.Count -eq 0) {
    docker compose
    exit $LASTEXITCODE
}

docker compose @args
exit $LASTEXITCODE
