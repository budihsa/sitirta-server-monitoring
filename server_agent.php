<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Jakarta');

/* ---------- CONFIG ---------- */
$SECRET = 'IsiDenganTokenRahasia'; // samakan dengan token di index.php

/* ---------- AUTH ---------- */
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($token !== $SECRET) {
    http_response_code(403);
    echo json_encode(array('error' => 'Forbidden'));
    exit;
}

/* ---------- HELPERS ---------- */
function readFirstLine($file) {
    if (!is_readable($file)) return null;
    $fh = fopen($file, 'r');
    if (!$fh) return null;
    $line = fgets($fh);
    fclose($fh);
    return $line === false ? null : trim($line);
}
function humanBytes($bytes) {
    $units = array('B','KB','MB','GB','TB','PB');
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) { $bytes /= 1024; $i++; }
    return array('value' => round($bytes, 2), 'unit' => $units[$i]);
}
function cpuUsagePercent($sleep_ms) {
    $s1 = @file('/proc/stat'); usleep($sleep_ms * 1000);
    $s2 = @file('/proc/stat');
    if (!$s1 || !$s2) return null;
    $c1 = preg_split('/\s+/', trim($s1[0]));
    $c2 = preg_split('/\s+/', trim($s2[0]));
    $idle1 = isset($c1[4]) ? (int)$c1[4] : 0; $idle2 = isset($c2[4]) ? (int)$c2[4] : 0;
    $iow1  = isset($c1[5]) ? (int)$c1[5] : 0; $iow2  = isset($c2[5]) ? (int)$c2[5] : 0;
    $idleAll1 = $idle1 + $iow1;
    $idleAll2 = $idle2 + $iow2;
    $total1 = 0; $total2 = 0;
    for ($i=1; $i<count($c1); $i++) $total1 += (int)$c1[$i];
    for ($i=1; $i<count($c2); $i++) $total2 += (int)$c2[$i];
    $totald = $total2 - $total1;
    $idled  = $idleAll2 - $idleAll1;
    if ($totald <= 0) return null;
    $usage = 100.0 * (1.0 - ($idled / $totald));
    if ($usage < 0) $usage = 0;
    if ($usage > 100) $usage = 100;
    return round($usage, 1);
}
function cpuCoreCount() {
    $c = @file_get_contents('/proc/cpuinfo');
    if (!$c) return null;
    preg_match_all('/^processor\s*:\s*\d+/m', $c, $m);
    return $m ? count($m[0]) : null;
}
function memInfo() {
    $mi = @file('/proc/meminfo');
    if (!$mi) return null;
    $kv = array();
    foreach ($mi as $line) {
        if (preg_match('/^(\w+):\s+(\d+)\s+kB$/', trim($line), $m)) {
            $kv[$m[1]] = (int)$m[2] * 1024; // bytes
        }
    }
    if (!isset($kv['MemTotal'])) return null;
    $total = $kv['MemTotal'];
    $avail = isset($kv['MemAvailable']) ? $kv['MemAvailable'] : (isset($kv['MemFree']) ? $kv['MemFree'] : 0);
    $used  = $total - $avail; // sesuai free -h (mem used = total - available)
    $pct   = $total > 0 ? round(($used / $total) * 100, 1) : 0.0;
    return array(
        'total'       => $total,
        'available'   => $avail,
        'used'        => $used,
        'free'        => $avail,                 
        'percent'     => $pct,
        'h_total'     => humanBytes($total),
        'h_used'      => humanBytes($used),
        'h_available' => humanBytes($avail),
        'h_free'      => humanBytes($avail)      
    );
}
function disksInfo() {
    // Ambil disk ala df -Th: gunakan df -PT -k untuk size dalam KB + jenis FS
    $out = @shell_exec('df -PT -k 2>/dev/null');
    if (!$out) return array();
    $lines = explode("\n", trim($out));
    array_shift($lines); // header

    $skip_types = array(
        'tmpfs','devtmpfs','squashfs','overlay','aufs','ramfs','cgroup','proc',
        'sysfs','debugfs','securityfs','pstore','efivarfs','mqueue','bpf',
        'tracefs','fusectl','autofs'
    );

    // (Opsional) whitelist filesystem nyata; jika kosong, semua selain skip_types akan masuk
    $real_fs_whitelist = array('ext2','ext3','ext4','xfs','btrfs','zfs','vfat','ntfs');

    $list = array();
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        $parts = preg_split('/\s+/', $ln);
        if (count($parts) < 7) continue;

        $fs     = $parts[0];
        $type   = $parts[1];
        $sizeK  = (int)$parts[2];
        $usedK  = (int)$parts[3];
        $availK = (int)$parts[4];
        $p      = $parts[5];
        $mnt    = $parts[6];

        if (in_array($type, $skip_types)) continue;
        if (!in_array(strtolower($type), $real_fs_whitelist)) continue; // jaga agar konsisten dg df -Th nyata

        $pct = (strpos($p, '%') !== false)
            ? (int)str_replace('%','',$p)
            : ( $sizeK > 0 ? round($usedK / $sizeK * 100) : 0 );

        $list[] = array(
            'fs'      => $fs,
            'type'    => $type,
            'mount'   => $mnt,
            'total'   => $sizeK * 1024,
            'used'    => $usedK * 1024,
            'avail'   => $availK * 1024,
            'percent' => (float)$pct,
            'h_total' => humanBytes($sizeK * 1024),
            'h_used'  => humanBytes($usedK * 1024),
            'h_avail' => humanBytes($availK * 1024)
        );
    }

    // Urutkan dari yang paling penuh (desc)
    usort($list, function($a,$b){
        if ($a['percent'] == $b['percent']) return 0;
        return ($a['percent'] < $b['percent']) ? 1 : -1;
    });

    return $list;
}
function diskSummary($entries) {
    // Ringkas semua mount nyata (contoh: "/" dan "/boot/efi")
    $total = 0; $used = 0; $avail = 0;
    foreach ($entries as $e) {
        $total += isset($e['total']) ? $e['total'] : 0;
        $used  += isset($e['used'])  ? $e['used']  : 0;
        $avail += isset($e['avail']) ? $e['avail'] : 0;
    }
    $pct = $total > 0 ? round(($used / $total) * 100, 1) : 0.0;
    return array(
        'total'   => $total,
        'used'    => $used,
        'avail'   => $avail,
        'free'    => $avail,            
        'percent' => $pct,
        'h_total' => humanBytes($total),
        'h_used'  => humanBytes($used),
        'h_avail' => humanBytes($avail),
        'h_free'  => humanBytes($avail) 
    );
}
function uptimeHuman() {
    $up = readFirstLine('/proc/uptime');
    if (!$up) return null;
    $sec = (int)floor((float)explode(' ', $up)[0]);
    $d = floor($sec/86400); $sec %= 86400;
    $h = floor($sec/3600);  $sec %= 3600;
    $m = floor($sec/60);
    $parts = array();
    if ($d>0) $parts[] = $d.'d';
    if ($h>0) $parts[] = $h.'h';
    $parts[] = $m.'m';
    return implode(' ', $parts);
}
function osRelease() {
    if (is_readable('/etc/os-release')) {
        $c = file('/etc/os-release');
        foreach ($c as $line) {
            if (strpos($line, 'PRETTY_NAME=') === 0) {
                $v = trim(substr($line, 12), "\" \n\r\t");
                return $v;
            }
        }
    }
    return php_uname('s').' '.php_uname('r');
}

/* ---------- COLLECT ---------- */
$load  = function_exists('sys_getloadavg') ? sys_getloadavg() : array(0,0,0);
$cores = cpuCoreCount();

$mem   = memInfo();
$disks = disksInfo();
$diskS = diskSummary($disks);

$data = array(
    'ts'       => date('Y-m-d H:i:s'),
    'hostname' => php_uname('n'),
    'kernel'   => php_uname('r'),
    'os'       => osRelease(),
    'ip'       => isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : @trim(shell_exec("hostname -I | awk '{print \$1}'")),
    'uptime'   => uptimeHuman(),
    'cpu'      => array(
        'percent' => cpuUsagePercent(500),
        'cores'   => $cores,
        'load1'   => isset($load[0]) ? round($load[0], 2) : null,
        'load5'   => isset($load[1]) ? round($load[1], 2) : null,
        'load15'  => isset($load[2]) ? round($load[2], 2) : null
    ),
    'mem'      => $mem,
    'disk'     => $diskS,   // ringkasan total
    'disks'    => $disks    // detail per mount
);

echo json_encode($data);
