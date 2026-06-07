<?php
/**
 * domain-check.php — ตรวจสุขภาพ plugin + theme ของ "โดเมนที่ระบุ" (เจาะจง)
 *                    ใช้เช็คซ้ำทีละเว็บก่อนลงมือแก้ไข
 *
 * อ่านอย่างเดียว 100% — ไม่ลบ ไม่แก้ ไม่ย้ายไฟล์ใด ๆ
 *
 * รันตรงจาก GitHub:
 *   URL='https://raw.githubusercontent.com/ufavisionseoteam19/single-domain-check/main/domain-check.php'
 *   curl -s "$URL?v=$(date +%s)" | php -- mcm5699.com                  # โดเมนเดียว (plugin+theme)
 *   curl -s "$URL?v=$(date +%s)" | php -- mcm5699.com naza99.biz       # หลายโดเมน
 *   curl -s "$URL?v=$(date +%s)" | php -- --plugins mcm5699.com        # เฉพาะ plugin
 *   curl -s "$URL?v=$(date +%s)" | php -- --themes mcm5699.com         # เฉพาะ theme
 *   curl -s "$URL?v=$(date +%s)" | php -- --list=CSV_URL               # ดึงรายชื่อจาก CSV บน GitHub
 *
 * Flags:
 *   --plugins      ตรวจเฉพาะ plugins
 *   --themes       ตรวจเฉพาะ themes
 *                  (ไม่ใส่ทั้งคู่ = ตรวจทั้งสองอย่าง)
 *   --list=URL     ดึงรายชื่อโดเมนจากไฟล์ CSV (คอลัมน์ domain, cpanel_user)
 *                  เช่น --list=https://raw.githubusercontent.com/.../remove-domains-list.csv
 *   --base=PATH    เปลี่ยนโฟลเดอร์ฐาน (ค่าเริ่มต้น = /home)
 *   --only-issues  แสดงเฉพาะตัวที่มีปัญหา
 * อาร์กิวเมนต์ที่ไม่ขึ้นต้นด้วย -- ถือเป็น "ชื่อโดเมน" (ใส่ได้หลายตัว)
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
if (function_exists('proc_nice')) { @proc_nice(19); }
@exec('ionice -c3 -p ' . getmypid() . ' 2>/dev/null');

// ====== ค่าตั้งต้น ======
$BASE        = '/home';
$DO_PLUGINS  = false;
$DO_THEMES   = false;
$ONLY_ISSUES = false;
$DOMAINS     = [];   // แต่ละตัว = ['domain'=>..., 'user'=>... หรือ null]
$LIST_URL    = null;
// เกณฑ์ (ตรงกับสคริปต์หลัก)
$PLUGIN_TH   = 3;
$THEME_TH    = 10;
$CHILD_NAME  = 'blocksy-child';
$CHILD_MIN   = 2;
$PARENT_NAME = 'blocksy';
$PARENT_MIN  = 50;
$DO_ERRLOG   = true;   // อ่าน error_log ของโดเมนด้วย
$LOG_DAYS    = 7;      // error log ย้อนหลังกี่วัน
$FRESH_HOURS = 6;      // error ใน N ชม. = ยังพังอยู่
$TZ          = 7;

// ====== อ่าน argument ======
global $argv;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--plugins')        { $DO_PLUGINS = true; }
    elseif ($a === '--themes')     { $DO_THEMES = true; }
    elseif ($a === '--only-issues'){ $ONLY_ISSUES = true; }
    elseif (strpos($a, '--base=') === 0) { $BASE = substr($a, 7); }
    elseif (strpos($a, '--list=') === 0) { $LIST_URL = substr($a, 7); }
    elseif ($a === '--no-errorlog') { $DO_ERRLOG = false; }
    elseif (strpos($a, '--log-days=') === 0) { $LOG_DAYS = max(1,(int)substr($a, 11)); }
    elseif (strpos($a, '--') === 0) { /* ไม่รู้จัก ข้าม */ }
    else { $DOMAINS[] = ['domain' => $a, 'user' => null]; }  // พิมพ์ตรง = ไม่รู้ user
}
if (!$DO_PLUGINS && !$DO_THEMES) { $DO_PLUGINS = true; $DO_THEMES = true; }

// ====== ดึงรายชื่อโดเมนจาก CSV (ถ้าระบุ --list) ======
if ($LIST_URL !== null) {
    fwrite(STDERR, "กำลังดึงรายชื่อโดเมนจาก: $LIST_URL\n");
    $csv = @file_get_contents($LIST_URL);
    if ($csv === false) {
        fwrite(STDERR, "** ดึง CSV ไม่สำเร็จ — ตรวจ URL หรือการเชื่อมต่อ **\n");
        exit(1);
    }
    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    $header = null;
    $di = 0; $ui = 1;  // ตำแหน่งคอลัมน์ domain, cpanel_user (ค่าเริ่มต้น)
    foreach ($lines as $i => $line) {
        if (trim($line) === '') continue;
        $cols = str_getcsv($line);
        // บรรทัดแรกเป็น header → หาตำแหน่งคอลัมน์
        if ($header === null) {
            $header = array_map('strtolower', array_map('trim', $cols));
            $fd = array_search('domain', $header);
            $fu = array_search('cpanel_user', $header);
            if ($fd !== false) $di = $fd;
            if ($fu !== false) $ui = $fu;
            // ถ้าบรรทัดแรกไม่ใช่ header (ไม่มีคำว่า domain) ให้ถือเป็นข้อมูลเลย
            if ($fd === false) {
                $dom = trim($cols[$di] ?? '');
                if ($dom !== '') $DOMAINS[] = ['domain' => $dom, 'user' => trim($cols[$ui] ?? '') ?: null];
            }
            continue;
        }
        $dom = trim($cols[$di] ?? '');
        if ($dom === '') continue;
        $usr = trim($cols[$ui] ?? '') ?: null;
        $DOMAINS[] = ['domain' => $dom, 'user' => $usr];
    }
    fwrite(STDERR, "ได้รายชื่อ " . count($DOMAINS) . " โดเมนจาก CSV\n\n");
}

if (count($DOMAINS) === 0) {
    fwrite(STDERR, "ใช้งาน: php domain-check.php -- <domain> [domain2 ...]\n");
    fwrite(STDERR, "    หรือ: php domain-check.php -- --list=<CSV_URL>\n");
    fwrite(STDERR, "ตัวอย่าง: php domain-check.php -- mcm5699.com naza99.biz\n");
    exit(1);
}

// ====== ฟังก์ชันช่วย ======
function count_files($dir) {
    $n = 0;
    $it = @new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY);
    if ($it === false) return 0;
    foreach ($it as $f) { if ($f->isFile()) $n++; }
    return $n;
}
function dir_size($dir) {
    $size = 0;
    $it = @new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY);
    if ($it === false) return 0;
    foreach ($it as $f) { if ($f->isFile()) $size += $f->getSize(); }
    return $size;
}
function human_size($b) {
    if ($b >= 1073741824) return round($b/1073741824,1).'G';
    if ($b >= 1048576) return round($b/1048576,1).'M';
    if ($b >= 1024) return round($b/1024,1).'K';
    return $b.'B';
}
function has_header($dir, $type) {
    if ($type === 'theme') {
        $css = "$dir/style.css";
        if (!is_file($css)) return false;
        $h = @file_get_contents($css, false, null, 0, 8192);
        return ($h !== false && stripos($h, 'Theme Name:') !== false);
    }
    foreach (glob("$dir/*.php") ?: [] as $f) {
        $h = @file_get_contents($f, false, null, 0, 8192);
        if ($h !== false && stripos($h, 'Plugin Name:') !== false) return true;
    }
    return false;
}
function missing_core_files($dir) {
    $req = ['style.css', 'index.php', 'functions.php'];
    $m = [];
    foreach ($req as $f) { if (!is_file("$dir/$f")) $m[] = $f; }
    return $m;
}

/** อ่าน error_log ของเว็บ หา Fatal error ล่าสุด
 *  $site_dir = โฟลเดอร์เว็บ (มี error_log อยู่ข้างใน)
 *  คืนค่า: array [time, msg, count] หรือ null ถ้าไม่มี */
function read_site_errorlog($site_dir, $log_days) {
    $log = "$site_dir/error_log";
    if (!is_file($log)) return null;
    $cutoff = time() - ($log_days * 86400);
    // อ่านท้ายไฟล์ 16KB
    $size = @filesize($log);
    $fh = @fopen($log, 'rb');
    if (!$fh) return null;
    if ($size > 16384) fseek($fh, -16384, SEEK_END);
    $tail = fread($fh, 16384);
    fclose($fh);

    $months = ['Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,
               'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12];
    $last = null; $cnt = 0;
    foreach (explode("\n", $tail) as $ln) {
        if (strpos($ln, 'PHP Fatal error') === false) continue;
        if (preg_match('/\[(\d{2})-([A-Za-z]{3})-(\d{4})\s+(\d{2}):(\d{2}):(\d{2})/', $ln, $m)) {
            $t = gmmktime((int)$m[4],(int)$m[5],(int)$m[6], $months[$m[2]]??1, (int)$m[1], (int)$m[3]);
            if ($t >= $cutoff) {
                $s = preg_replace('/^\[[^\]]+\]\s*/', '', $ln);
                if (preg_match('/(Uncaught .*?)(?: in |$)/', $s, $mm)) $s = $mm[1];
                if (strlen($s) > 100) $s = substr($s, 0, 100) . '...';
                $last = ['time'=>$t, 'msg'=>trim($s)]; $cnt++;
            }
        }
    }
    if ($last === null) return null;
    $last['count'] = $cnt;
    return $last;
}

/** หา wp-content ของโดเมนที่ระบุ — ค้นจาก path ที่มีชื่อโดเมน
 *  ถ้ารู้ $user → ค้นเฉพาะ /home/$user (เร็วกว่ามาก)
 *  ถ้าไม่รู้ → ค้นทั้ง $base */
function find_domain_wpcontent($base, $domain, $user = null) {
    $root = ($user !== null && is_dir("$base/$user")) ? "$base/$user" : $base;
    $found = [];
    $stack = [[$root, 0]];
    $skip = ['node_modules','.git','cache','.cache','tmp','logs'];
    while ($stack) {
        list($dir, $depth) = array_pop($stack);
        $dh = @opendir($dir);
        if ($dh === false) continue;
        while (($e = readdir($dh)) !== false) {
            if ($e === '.' || $e === '..') continue;
            $p = "$dir/$e";
            if (!is_dir($p) || is_link($p)) continue;
            if ($e === 'wp-content') {
                if (strpos($p, $domain) !== false) $found[] = $p;
                continue;
            }
            if (in_array($e, $skip, true)) continue;
            if ($depth + 1 <= 6) { $stack[] = [$p, $depth + 1]; }
        }
        closedir($dh);
    }
    return $found;
}

// ====== ตรวจแต่ละโดเมน ======
echo "=======================================================\n";
echo " Domain Health Check (เจาะจงโดเมน, read-only)\n";
echo "=======================================================\n";
echo " วันเวลา : " . date('Y-m-d H:i:s') . "\n";
echo " ตรวจ    : " . trim(($DO_PLUGINS?'plugins ':'').($DO_THEMES?'themes':'')) . "\n";
echo " โดเมน   : " . implode(', ', array_column($DOMAINS, 'domain')) . "\n\n";

foreach ($DOMAINS as $entry) {
    $domain = $entry['domain'];
    $user   = $entry['user'];
    echo "#######################################################\n";
    echo "# โดเมน: $domain" . ($user ? "  (บัญชี: $user)" : "") . "\n";
    echo "#######################################################\n";

    $wpcs = find_domain_wpcontent($BASE, $domain, $user);
    if (count($wpcs) === 0) {
        echo "  ** ไม่พบเว็บ WordPress ของโดเมนนี้ (อาจไม่มี wp-content หรือพิมพ์ชื่อผิด) **\n\n";
        continue;
    }

    foreach ($wpcs as $wpc) {
        $site = preg_replace('#/wp-content$#', '', $wpc);
        echo "\n  ที่ตั้ง: $site\n";

        $types = [];
        if ($DO_PLUGINS) $types['plugins'] = 'plugin';
        if ($DO_THEMES)  $types['themes']  = 'theme';

        foreach ($types as $sub => $type) {
            $container = "$wpc/$sub";
            if (!is_dir($container)) {
                echo "    [" . strtoupper($type) . "] ไม่มีโฟลเดอร์ $sub\n";
                continue;
            }
            $entries = glob("$container/*", GLOB_ONLYDIR);
            sort($entries);
            echo "    [" . strtoupper($type) . "]\n";
            printf("    %-12s %-7s %-9s %s\n", 'สถานะ', 'ไฟล์', 'ขนาด', 'ชื่อ');

            $found_issue = false;
            foreach ($entries as $d) {
                $name = basename($d);
                $fcount = count_files($d);
                $size = human_size(dir_size($d));

                if ($type === 'theme') {
                    if ($fcount === 0) { $status = 'ว่าง!'; }
                    elseif ($name === $PARENT_NAME) {
                        $miss = missing_core_files($d);
                        if (!empty($miss)) $status = 'ไฟล์หลักขาด:' . implode(',', $miss);
                        elseif ($fcount <= $PARENT_MIN) $status = 'สงสัย';
                        else $status = 'ปกติ';
                    }
                    elseif ($name === $CHILD_NAME) {
                        if ($fcount <= $CHILD_MIN) $status = 'สงสัย';
                        elseif (!has_header($d,'theme')) $status = 'ไม่มีหลัก';
                        else $status = 'ปกติ';
                    }
                    elseif ($fcount <= $THEME_TH) $status = 'สงสัย';
                    elseif (!has_header($d,'theme')) $status = 'ไม่มีหลัก';
                    else $status = 'ปกติ';
                } else {
                    if ($fcount === 0) $status = 'ว่าง!';
                    elseif ($fcount <= $PLUGIN_TH) $status = 'สงสัย';
                    elseif (!has_header($d,'plugin')) $status = 'ไม่มีหลัก';
                    else $status = 'ปกติ';
                }

                if ($status !== 'ปกติ') $found_issue = true;
                if ($ONLY_ISSUES && $status === 'ปกติ') continue;
                printf("    %-12s %-7s %-9s %s\n", $status, $fcount, $size, $name);
            }
            if ($ONLY_ISSUES && !$found_issue) echo "    (ไม่พบปัญหาจากการนับไฟล์)\n";
        }

        // อ่าน error_log ของเว็บนี้ (จับเคสไฟล์ลึกหายที่นับไฟล์มองไม่เห็น)
        if ($DO_ERRLOG) {
            $err = read_site_errorlog($site, $LOG_DAYS);
            echo "    [ERROR LOG]\n";
            if ($err === null) {
                echo "    ไม่พบ Fatal error ใน $LOG_DAYS วันล่าสุด (หรือไม่มีไฟล์ error_log)\n";
            } else {
                $age_h = (time() - $err['time']) / 3600;
                $flag = ($age_h <= $FRESH_HOURS) ? '[ยังพังอยู่]' : '[อาจแก้แล้ว]';
                $thai = gmdate('Y-m-d H:i', $err['time'] + $TZ*3600) . " (+$TZ)";
                echo "    $flag  เวลาล่าสุด: $thai  (เกิด {$err['count']}+ ครั้ง)\n";
                echo "    สาเหตุ: {$err['msg']}\n";
            }
        }
        echo "\n";
    }
}
echo "หมายเหตุ: สคริปต์นี้อ่านอย่างเดียว ไม่แก้ไขไฟล์ใด ๆ\n";
