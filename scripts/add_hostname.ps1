#Requires -Version 5.1
<#
.SYNOPSIS
  Apply env.json (+ runtime/hosts.extra.json) domains into the Windows hosts file.
.DESCRIPTION
  Same role as scripts/add_hostname.sh, for native Windows PowerShell.

  One-shot:
    powershell -ExecutionPolicy Bypass -File .\scripts\add_hostname.ps1

  Watch Manager requests (runtime/hosts.sync):
    powershell -ExecutionPolicy Bypass -File .\scripts\add_hostname.ps1 -Watch

  Force UAC elevation:
    powershell -ExecutionPolicy Bypass -File .\scripts\add_hostname.ps1 -ForceAdmin
#>
param(
    [switch]$Watch,
    [switch]$Once,
    [switch]$ForceAdmin
)

$ErrorActionPreference = 'Stop'

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RepoRoot = Split-Path -Parent $ScriptDir
$RuntimeDir = Join-Path $RepoRoot 'runtime'
$EnvJson = Join-Path $RepoRoot 'env.json'
$SyncFile = Join-Path $RuntimeDir 'hosts.sync'
$StatusFile = Join-Path $RuntimeDir 'hosts.status.json'
$ExtraFile = Join-Path $RuntimeDir 'hosts.extra.json'
$HostsPath = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
$Begin = '# multi-php-docker-serve:managed:begin'
$End = '# multi-php-docker-serve:managed:end'
$Ip = '127.0.0.1'

New-Item -ItemType Directory -Force -Path $RuntimeDir | Out-Null

function Get-DesiredDomains {
    $set = @{}
    if (Test-Path -LiteralPath $EnvJson) {
        $raw = Get-Content -LiteralPath $EnvJson -Raw -Encoding utf8 | ConvertFrom-Json
        foreach ($prop in $raw.PSObject.Properties) {
            if ($prop.Name -match '^SERVER_NAME') {
                $domain = [string]$prop.Value.DOMAIN_NAME
                if ($domain) {
                    $key = $domain.Trim().ToLowerInvariant()
                    if ($key) { $set[$key] = $true }
                }
            }
        }
    }
    if (Test-Path -LiteralPath $ExtraFile) {
        $extrasRaw = Get-Content -LiteralPath $ExtraFile -Raw -Encoding utf8
        if ($extrasRaw -and $extrasRaw.Trim() -ne '') {
            $extras = $extrasRaw | ConvertFrom-Json
            $items = @()
            if ($extras -is [System.Array]) { $items = @($extras) }
            elseif ($null -ne $extras) { $items = @($extras) }
            foreach ($item in $items) {
                $domain = [string]$item
                if ($domain) {
                    $key = $domain.Trim().ToLowerInvariant()
                    if ($key) { $set[$key] = $true }
                }
            }
        }
    }
    return @($set.Keys | Sort-Object)
}

function Build-DomainStates([string[]]$Domains, [string]$State) {
    $map = @{}
    foreach ($domain in @($Domains)) {
        if ($domain) { $map[$domain] = $State }
    }
    return $map
}

function Build-Manual([string[]]$Domains) {
    return [ordered]@{
        hosts_path = $HostsPath
        lines = @(@($Domains) | Where-Object { $_ } | ForEach-Object { "$Ip $_" })
    }
}

function Write-HostsStatus {
    param(
        [string]$Status,
        [string]$MessageKey,
        [hashtable]$Domains = @{},
        $Manual = $null
    )
    $payload = [ordered]@{
        status = $Status
        message_key = $MessageKey
        updated_at = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
        domains = $Domains
    }
    if ($null -ne $Manual) { $payload.manual = $Manual }
    $json = ($payload | ConvertTo-Json -Compress -Depth 6)
    $utf8NoBom = New-Object System.Text.UTF8Encoding $false
    [System.IO.File]::WriteAllText($StatusFile, $json + [Environment]::NewLine, $utf8NoBom)
}

function Write-HostsFile([string[]]$Domains) {
    $domains = @($Domains | ForEach-Object { $_.Trim().ToLowerInvariant() } | Where-Object { $_ } | Sort-Object -Unique)
    $managedLines = @($Begin) + @($domains | ForEach-Object { "$Ip $_" }) + @($End)

    if (-not (Test-Path -LiteralPath $HostsPath)) {
        throw "Hosts file not found: $HostsPath"
    }

    $content = Get-Content -LiteralPath $HostsPath -ErrorAction Stop
    $out = New-Object System.Collections.Generic.List[string]
    $inBlock = $false
    $replaced = $false

    foreach ($line in $content) {
        if ($line.Trim() -eq $Begin) {
            $inBlock = $true
            if (-not $replaced) {
                foreach ($managed in $managedLines) { $out.Add($managed) }
                $replaced = $true
            }
            continue
        }
        if ($line.Trim() -eq $End) {
            $inBlock = $false
            continue
        }
        if (-not $inBlock) { $out.Add($line) }
    }

    if (-not $replaced) {
        if ($out.Count -gt 0 -and $out[$out.Count - 1].Trim() -ne '') { $out.Add('') }
        foreach ($managed in $managedLines) { $out.Add($managed) }
    }

    $temp = Join-Path $env:TEMP ("multi-php-hosts-" + [guid]::NewGuid().ToString('N') + ".txt")
    [System.IO.File]::WriteAllLines($temp, $out.ToArray(), [System.Text.Encoding]::ASCII)
    try {
        Copy-Item -LiteralPath $temp -Destination $HostsPath -Force -ErrorAction Stop
    } finally {
        Remove-Item -LiteralPath $temp -Force -ErrorAction SilentlyContinue
    }
}

function Invoke-HostsWrite([string[]]$Domains, [switch]$Elevated) {
    $domainArgs = (@($Domains) | ForEach-Object { $_ }) -join ','
    # Re-enter this script elevated for the actual write when needed.
    $argList = "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`" -Once"
    if ($Elevated) {
        $proc = Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList $argList -Wait -PassThru
        if ($null -eq $proc) { return 2 }
        return [int]$proc.ExitCode
    }

    try {
        Write-HostsFile -Domains $Domains
        return 0
    } catch [System.UnauthorizedAccessException] {
        return 2
    } catch {
        if ($_.Exception.Message -match 'Access is denied|UnauthorizedAccess|permission') { return 2 }
        Write-Host $_.Exception.Message
        return 1
    }
}

function Invoke-Apply([switch]$PreferAdmin) {
    if (-not (Test-Path -LiteralPath $EnvJson)) {
        throw "env.json not found at $EnvJson"
    }

    $domains = @(Get-DesiredDomains)
    Write-Host ("Applying domains: {0}" -f ($(if ($domains.Count) { $domains -join ', ' } else { '(none)' })))
    $unknown = Build-DomainStates -Domains $domains -State 'unknown'

    if ($PreferAdmin) {
        Write-HostsStatus -Status 'busy' -MessageKey 'hosts.elevation_required' -Domains $unknown
        Write-Host 'Requesting Administrator permission...'
        $code = Invoke-HostsWrite -Domains $domains -Elevated
    } else {
        Write-HostsStatus -Status 'busy' -MessageKey 'hosts.processing' -Domains $unknown
        $code = Invoke-HostsWrite -Domains $domains
        if ($code -eq 2) {
            Write-Host 'Access denied. Requesting Administrator permission...'
            Write-HostsStatus -Status 'busy' -MessageKey 'hosts.elevation_required' -Domains $unknown
            $code = Invoke-HostsWrite -Domains $domains -Elevated
        }
    }

    if ($code -eq 0) {
        Write-HostsStatus -Status 'success' -MessageKey 'hosts.sync_success' -Domains (Build-DomainStates -Domains $domains -State 'synced')
        Write-Host 'Hosts updated successfully.'
        Remove-Item -LiteralPath $SyncFile -Force -ErrorAction SilentlyContinue
        return 0
    }

    Write-HostsStatus -Status 'error' -MessageKey 'hosts.manual_required' -Domains $unknown -Manual (Build-Manual -Domains $domains)
    Write-Host 'Hosts update failed. Add entries manually if needed.'
    Remove-Item -LiteralPath $SyncFile -Force -ErrorAction SilentlyContinue
    return $code
}

# Elevated child process: only write, then exit.
if ($Once -and -not $Watch) {
    try {
        Write-HostsFile -Domains (Get-DesiredDomains)
        exit 0
    } catch [System.UnauthorizedAccessException] {
        [Console]::Error.WriteLine($_.Exception.Message)
        exit 2
    } catch {
        [Console]::Error.WriteLine($_.Exception.Message)
        if ($_.Exception.Message -match 'Access is denied|UnauthorizedAccess|permission') { exit 2 }
        exit 1
    }
}

if ($Watch) {
    Write-Host "add_hostname watching $SyncFile"
    Write-Host 'Flow: write hosts -> elevate if needed -> manual instructions on failure'
    Write-Host 'Press Ctrl+C to stop.'
    while ($true) {
        if (Test-Path -LiteralPath $SyncFile) {
            $preferAdmin = $ForceAdmin.IsPresent
            try {
                $syncPayload = Get-Content -LiteralPath $SyncFile -Raw -Encoding utf8 | ConvertFrom-Json
                if ($syncPayload.force_admin -eq $true) { $preferAdmin = $true }
            } catch { }
            Write-Host ("[{0}] Request received (force_admin={1})" -f (Get-Date -Format 'HH:mm:ss'), $preferAdmin)
            try {
                [void](Invoke-Apply -PreferAdmin:$preferAdmin)
            } catch {
                Write-Host ("[{0}] Sync failed: {1}" -f (Get-Date -Format 'HH:mm:ss'), $_.Exception.Message)
            } finally {
                Remove-Item -LiteralPath $SyncFile -Force -ErrorAction SilentlyContinue
                Write-Host ("[{0}] Waiting for next request..." -f (Get-Date -Format 'HH:mm:ss'))
            }
        }
        Start-Sleep -Seconds 1
    }
}

exit (Invoke-Apply -PreferAdmin:$ForceAdmin)
