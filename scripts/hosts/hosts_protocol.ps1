#Requires -Version 5.1
<#
.SYNOPSIS
  URL protocol handler for multi-php-hosts: (registered by ensure_hosts_env.ps1).
.DESCRIPTION
  Browser opens multi-php-hosts://write?id=<token> → wait for matching runtime/hosts.sync
  → add_hostname.ps1 -ForceAdmin.
#>
param(
    [Parameter(Mandatory = $false, Position = 0)]
    [string]$Url = 'multi-php-hosts://write'
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Split-Path -Parent (Split-Path -Parent $ScriptDir)
$AddHostname = Join-Path $ScriptDir 'add_hostname.ps1'
$RuntimeDir = Join-Path $RepoRoot 'runtime'
$SyncFile = Join-Path $RuntimeDir 'hosts.sync'
$LogFile = Join-Path $RuntimeDir 'hosts.protocol.log'

function Write-ProtocolLog([string]$Message) {
    try {
        New-Item -ItemType Directory -Force -Path $RuntimeDir | Out-Null
        $line = '{0} {1}' -f (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ'), $Message
        Add-Content -LiteralPath $LogFile -Value $line
    } catch { }
}

# Accept multi-php-hosts:write, multi-php-hosts://write, ?id=token, or legacy write/token
$raw = ($Url -replace '^multi-php-hosts:(?://)?', '').Trim().TrimStart('/')
$action = ($raw -split '[/?#]', 2)[0].ToLowerInvariant()
$token = ''
if ($raw -match '[?&]id=([a-fA-F0-9]{8,64})') {
    $token = $Matches[1].ToLowerInvariant()
} elseif ($raw -match '^[wW][rR][iI][tT][eE][/]([a-fA-F0-9]{8,64})') {
    $token = $Matches[1].ToLowerInvariant()
}
if ([string]::IsNullOrWhiteSpace($action)) {
    $action = 'write'
}

Write-ProtocolLog ("url={0} action={1} token={2}" -f $Url, $action, $token)

if ($action -ne 'write') {
    Write-ProtocolLog "unsupported action"
    [Console]::Error.WriteLine("Unsupported multi-php-hosts action: $action")
    exit 1
}

if (-not (Test-Path -LiteralPath $AddHostname)) {
    Write-ProtocolLog ("missing add_hostname at {0}" -f $AddHostname)
    [Console]::Error.WriteLine("add_hostname.ps1 not found at $AddHostname")
    exit 1
}

function Wait-HostsSyncToken {
    param(
        [string]$RequestId,
        [int]$TimeoutSec = 45
    )
    if ([string]::IsNullOrWhiteSpace($RequestId)) {
        return $true
    }
    $deadline = (Get-Date).AddSeconds($TimeoutSec)
    while ((Get-Date) -lt $deadline) {
        if (Test-Path -LiteralPath $SyncFile) {
            try {
                $payload = Get-Content -LiteralPath $SyncFile -Raw -Encoding utf8 | ConvertFrom-Json
                if ([string]$payload.request_id -eq $RequestId) {
                    return $true
                }
            } catch { }
        }
        Start-Sleep -Milliseconds 300
    }
    return $false
}

# Protocol is launched on the user click, before Manager finishes writing hosts.sync.
if (-not (Wait-HostsSyncToken -RequestId $token)) {
    Write-ProtocolLog ("timeout waiting for request_id={0}" -f $token)
    [Console]::Error.WriteLine("Timed out waiting for hosts.sync request_id=$token")
    exit 0
}

Write-ProtocolLog 'starting add_hostname -ForceAdmin'
$proc = Start-Process -FilePath 'powershell.exe' -ArgumentList @(
    '-NoProfile',
    '-ExecutionPolicy', 'Bypass',
    '-File', $AddHostname,
    '-ForceAdmin'
) -Wait -PassThru

$code = if ($null -eq $proc) { 1 } else { [int]$proc.ExitCode }
Write-ProtocolLog ("add_hostname exit={0}" -f $code)
exit $code
