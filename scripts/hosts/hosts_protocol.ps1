#Requires -Version 5.1
<#
.SYNOPSIS
  URL protocol handler for multi-php-hosts: (registered by ensure_hosts_env.ps1).
.DESCRIPTION
  Browser opens multi-php-hosts:write → this script → add_hostname.ps1 -ForceAdmin.
#>
param(
    [Parameter(Mandatory = $false, Position = 0)]
    [string]$Url = 'multi-php-hosts:write'
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$AddHostname = Join-Path $ScriptDir 'add_hostname.ps1'

# Accept multi-php-hosts:write, multi-php-hosts://write, multi-php-hosts:write?...
$raw = ($Url -replace '^multi-php-hosts:(?://)?', '').Trim()
$action = ($raw -split '[/?#]', 2)[0].ToLowerInvariant()
if ([string]::IsNullOrWhiteSpace($action)) {
    $action = 'write'
}

if ($action -ne 'write') {
    [Console]::Error.WriteLine("Unsupported multi-php-hosts action: $action")
    exit 1
}

if (-not (Test-Path -LiteralPath $AddHostname)) {
    [Console]::Error.WriteLine("add_hostname.ps1 not found at $AddHostname")
    exit 1
}

$proc = Start-Process -FilePath 'powershell.exe' -ArgumentList @(
    '-NoProfile',
    '-ExecutionPolicy', 'Bypass',
    '-File', $AddHostname,
    '-ForceAdmin'
) -Wait -PassThru

exit $(if ($null -eq $proc) { 1 } else { [int]$proc.ExitCode })
