#Requires -Version 5.1
<#
.SYNOPSIS
  Run ensure_hosts_env, then forward all args to docker compose.
.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\scripts\compose.ps1 up -d
  powershell -ExecutionPolicy Bypass -File .\scripts\compose.ps1 --profile php-8.1 up -d
#>
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$ComposeArgs
)

$ErrorActionPreference = 'Stop'

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$EnsureScript = Join-Path $ScriptDir 'ensure_hosts_env.ps1'

if (-not (Test-Path -LiteralPath $EnsureScript)) {
    throw "Missing $EnsureScript"
}

& $EnsureScript
if ($LASTEXITCODE -ne 0 -and $null -ne $LASTEXITCODE) {
    exit $LASTEXITCODE
}

if ($null -eq $ComposeArgs -or $ComposeArgs.Count -eq 0) {
    docker compose
    exit $LASTEXITCODE
}

docker compose @ComposeArgs
exit $LASTEXITCODE
