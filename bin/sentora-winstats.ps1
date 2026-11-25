<#
  Server Monitor on Windows: the figures the page shows, every 3 seconds, in the panel's state folder
  (monitor\winstats.json), as Linux's /proc gives them to the page (modules/server_monitor/code/stats.php reads them).
  Run as SYSTEM by the service SentoraStats (made by the Windows installer), so the panel's PHP needs no access to
  Windows' own counters. Process names only, never command lines (they can hold passwords).

    sentora-winstats.ps1 -Out C:\zpanel\data\sentora\monitor\winstats.json [-Once]

  Counters are kept as running totals (CPU time in 10 ms ticks, bytes), as on Linux: the page turns two samples into
  rates. Windows has no load average: "load" is the processor queue length.

  @package Sentora
  @license GPL-3.0 https://www.gnu.org/licenses/gpl-3.0.html
  Written by RusTus, 2026-11-25
#>
param([Parameter(Mandatory = $true)][string]$Out, [switch]$Once)
$ErrorActionPreference = 'Continue'
$ProgressPreference = 'SilentlyContinue'

function Sample {
    $o = [ordered]@{ t = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds() / 1000.0 }
    # CPU: user nice system idle iowait irq softirq steal, in 10 ms ticks (the raw counters are in 100 ns)
    $cpu = [ordered]@{}
    foreach ($c in @(Get-CimInstance Win32_PerfRawData_PerfOS_Processor)) {
        $n = if ($c.Name -eq '_Total') { 'cpu' } else { 'cpu' + $c.Name }
        $u = [int64]($c.PercentUserTime / 100000); $p = [int64]($c.PercentPrivilegedTime / 100000)
        $i = [int64]($c.PercentIdleTime / 100000); $q = [int64]($c.PercentInterruptTime / 100000)
        $cpu[$n] = @($u, 0, [math]::Max([int64]0, $p - $q), $i, 0, $q, 0, 0)
    }
    $o.cpu = $cpu
    $sys = Get-CimInstance Win32_PerfFormattedData_PerfOS_System
    $ql = [double]$sys.ProcessorQueueLength
    $o.running = [int]$sys.ProcessorQueueLength
    $o.load = @($ql, $ql, $ql)
    $os = Get-CimInstance Win32_OperatingSystem
    $o.uptime = [double]((Get-Date) - $os.LastBootUpTime).TotalSeconds
    $memc = Get-CimInstance Win32_PerfRawData_PerfOS_Memory
    $total = [int64]$os.TotalVisibleMemorySize * 1024
    $avail = [int64]$memc.AvailableBytes
    $swapT = 0; $swapU = 0
    foreach ($pf in @(Get-CimInstance Win32_PageFileUsage)) { $swapT += [int64]$pf.AllocatedBaseSize * 1MB; $swapU += [int64]$pf.CurrentUsage * 1MB }
    $o.mem = [ordered]@{ total = $total; available = $avail; free = [int64]$os.FreePhysicalMemory * 1024; buffers = 0
        cached = [int64]$memc.StandbyCacheNormalPriorityBytes + [int64]$memc.StandbyCacheReserveBytes + [int64]$memc.StandbyCacheCoreBytes
        swapTotal = $swapT; swapFree = $swapT - $swapU }
    # network: bytes in and out per adapter (running totals)
    $net = [ordered]@{}
    foreach ($a in @(Get-CimInstance Win32_PerfRawData_Tcpip_NetworkInterface)) {
        if ($a.Name -match 'isatap|Loopback|Teredo') { continue }
        $net[$a.Name] = @([int64]$a.BytesReceivedPersec, [int64]$a.BytesSentPersec)
    }
    $o.net = $net
    # disk I/O: bytes read and written per physical disk (running totals)
    $io = [ordered]@{}
    foreach ($d in @(Get-CimInstance Win32_PerfRawData_PerfDisk_PhysicalDisk)) {
        if ($d.Name -eq '_Total') { continue }
        $io[$d.Name] = @([int64]$d.DiskReadBytesPersec, [int64]$d.DiskWriteBytesPersec)
    }
    $o.io = $io
    # disk space per local disk
    $o.fs = @(Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' | Where-Object { $_.Size -gt 0 } |
        ForEach-Object { [ordered]@{ mount = $_.DeviceID; total = [int64]$_.Size; free = [int64]$_.FreeSpace } })
    # processes: pid, name, owner, CPU time in 10 ms ticks, memory in use (each section on its own: one that fails keeps
    # its last good value, the others are still written)
    try {
        $o.procs = @(Get-Process -IncludeUserName -ErrorAction SilentlyContinue | Where-Object { $_.Id -ne 0 } | ForEach-Object {
            $ticks = 0; try { $ticks = [int64]($_.TotalProcessorTime.Ticks / 100000) } catch { }
            $user = "$($_.UserName)"; if ($user -match '\\([^\\]+)$') { $user = $Matches[1] }
            , @([int]$_.Id, "$($_.ProcessName)", $(if ($user) { $user } else { '-' }), $ticks, [int64]$_.WorkingSet64)
        })
        if ($o.procs.Count -gt 0) { $script:lastProcs = $o.procs }
    } catch { }
    if (-not $o.procs -or $o.procs.Count -eq 0) { $o.procs = $script:lastProcs }
    # the services and whether each runs (the page picks the panel's by the names in its settings)
    $svc = [ordered]@{}
    try { foreach ($s in @(Get-Service -ErrorAction SilentlyContinue)) { $svc[$s.Name] = ($s.Status -eq 'Running') } } catch { }
    if ($svc.Count -gt 0) { $script:lastSvc = $svc } elseif ($script:lastSvc) { $svc = $script:lastSvc }
    $o.services = $svc
    $cpuName = (@(Get-CimInstance Win32_Processor) | Select-Object -First 1).Name
    $o.info = [ordered]@{ host = $env:COMPUTERNAME; kernel = "$($os.Version)"; os = "$($os.Caption)"; cpu = "$cpuName".Trim()
        cores = [math]::Max(1, $cpu.Count - 1); hz = 100 }
    return $o
}

$script:lastProcs = @(); $script:lastSvc = $null
$dir = Split-Path -Parent $Out
if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
while ($true) {
    try {
        $json = (Sample) | ConvertTo-Json -Depth 5 -Compress
        [IO.File]::WriteAllText("$Out.new", $json)
        Move-Item -LiteralPath "$Out.new" -Destination $Out -Force
    } catch {
        [Console]::Error.WriteLine("$(Get-Date -Format s) $($_.Exception.Message)")
    }
    if ($Once) { break }
    Start-Sleep -Seconds 3
}
