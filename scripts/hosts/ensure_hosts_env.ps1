#Requires -Version 5.1
<#
.SYNOPSIS
  Detect OS hosts path for docker compose, and register the multi-php-hosts: protocol.
.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\scripts\hosts\ensure_hosts_env.ps1
  powershell -ExecutionPolicy Bypass -File .\scripts\hosts\ensure_hosts_env.ps1 -UnregisterProtocol
#>
param(
    [switch]$UnregisterProtocol,
    [switch]$SkipProtocol
)

$ErrorActionPreference = 'Stop'

$RepoRoot = Split-Path -Parent (Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path))
$EnvFile = Join-Path $RepoRoot '.env'
$ProtocolScript = Join-Path $RepoRoot 'scripts\hosts\hosts_protocol.ps1'
$ProtocolName = 'multi-php-hosts'
$ClassesRoot = "HKCU:\Software\Classes\$ProtocolName"

function Unregister-HostsProtocol {
    if (Test-Path -LiteralPath $ClassesRoot) {
        Remove-Item -LiteralPath $ClassesRoot -Recurse -Force
        Write-Host "Removed HKCU protocol $ProtocolName"
    } else {
        Write-Host "Protocol $ProtocolName was not registered"
    }
}

function Register-HostsProtocol {
    if (-not (Test-Path -LiteralPath $ProtocolScript)) {
        throw "Protocol handler missing: $ProtocolScript"
    }

    $command = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}" "%1"' -f $ProtocolScript

    New-Item -Path $ClassesRoot -Force | Out-Null
    New-ItemProperty -Path $ClassesRoot -Name '(Default)' -Value 'URL:Multi PHP Hosts Protocol' -PropertyType String -Force | Out-Null
    New-ItemProperty -Path $ClassesRoot -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null
    New-Item -Path "$ClassesRoot\shell\open\command" -Force | Out-Null
    New-ItemProperty -Path "$ClassesRoot\shell\open\command" -Name '(Default)' -Value $command -PropertyType String -Force | Out-Null

    $registered = [string](Get-ItemProperty -LiteralPath "$ClassesRoot\shell\open\command").'(default)'
    if ($registered -notlike ('*{0}*' -f $ProtocolScript)) {
        throw "Protocol command does not point at $ProtocolScript"
    }
    if ($registered -match '"([^"]+\.ps1)"' -and -not (Test-Path -LiteralPath $Matches[1])) {
        throw "Protocol handler missing: $($Matches[1])"
    }
    Write-Host "Registered ${ProtocolName}: → $ProtocolScript"
}

if ($UnregisterProtocol) {
    Unregister-HostsProtocol
    exit 0
}

$HostsPath = (Join-Path $env:SystemRoot 'System32\drivers\etc\hosts') -replace '\\', '/'

if (-not (Test-Path -LiteralPath $HostsPath)) {
    throw "Hosts file not found at $HostsPath"
}

# Docker Desktop bind mounts for compose create need the absolute host path.
$ProjectPath = $RepoRoot -replace '\\', '/'

function Set-EnvLine {
    param(
        [string]$Key,
        [string]$Value
    )
    $line = "$Key=$Value"
    if (Test-Path -LiteralPath $EnvFile) {
        $content = Get-Content -LiteralPath $EnvFile -Raw
        if ($null -eq $content) { $content = '' }
        $pattern = "(?m)^$([regex]::Escape($Key))="
        if ($content -match $pattern) {
            $content = [regex]::Replace($content, "(?m)^$([regex]::Escape($Key))=.*$", $line)
        } else {
            if ($content.Length -gt 0 -and -not $content.EndsWith("`n")) {
                $content += "`n"
            }
            $content += "$line`n"
        }
        Set-Content -LiteralPath $EnvFile -Value $content -NoNewline
    } else {
        Set-Content -LiteralPath $EnvFile -Value "$line`n" -NoNewline
    }
    Write-Host "Wrote $line to .env"
}

Set-EnvLine -Key 'HOSTS_FILE' -Value $HostsPath
Set-EnvLine -Key 'HOST_PROJECT_PATH' -Value $ProjectPath

if (-not $SkipProtocol) {
    Register-HostsProtocol
}
