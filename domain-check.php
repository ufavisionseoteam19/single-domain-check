<?php
/**
 * domain-check.php — ตรวจสุขภาพ plugin + theme ของ "โดเมนที่ระบุ" (เจาะจง)
 *                    ใช้เช็คซ้ำทีละเว็บก่อน/หลังลงมือแก้ไข
 *
 * อ่านอย่างเดียว 100% — ไม่ลบ ไม่แก้ ไม่ย้ายไฟล์ใด ๆ
 *
 * รันตรงจาก GitHub:
 *   URL='https://raw.githubusercontent.com/ufavisionseoteam19/single-domain-check/main/domain-check.php'
 *   curl -s "$URL?v=$(date +%s)" | php -- mcm5699.com                  # โดเมนเดียว (plugin+theme)
 *   curl -s "$URL?v=$(date +%s)" | php -- mcm5699.com naza99.biz       # หลายโดเมน
 *   curl -s "$URL?v=$(date +%s)" | php -- kplus888.org:newkeydec2025   # ระบุบัญชี cPanel ด้วย (หาเจอเร็วกว่ามาก)
 *   curl -s "$URL?v=$(date +%s)" | php -- --plugins mcm5699.com        # เฉพาะ plugin
 *   curl -s "$URL?v=$(date +%s)" | php -- --themes mcm5699.com         # เฉพาะ theme
 *   curl -s "$URL?v=$(date +%s)" | php -- --list=CSV_URL               # ดึงรายชื่อจาก CSV บน GitHub
 *
 * Flags:
 *   --plugins        ตรวจเฉพาะ plugins
 *   --themes         ตรวจเฉพาะ themes
 *                    (ไม่ใส่ทั้งคู่ = ตรวจทั้งสองอย่าง)
 *   --list=URL       ดึงรายชื่อโดเมนจากไฟล์ CSV (คอลัมน์ domain, cpanel_user)
 *   --base=PATH      เปลี่ยนโฟลเดอร์ฐาน (ค่าเริ่มต้น = /home)
 *   --only-issues    แสดงเฉพาะตัวที่มีปัญหา
 *   --no-errorlog    ไม่ต้องอ่าน error_log
 *   --log-days=N     error log ย้อนหลังกี่วัน (ค่าเริ่มต้น 7)
 *   --json           แถมผลแบบ JSON ต่อท้ายรายงาน (หลังบรรทัด ===JSON===)
 *                    สำหรับให้แอป/สคริปต์อื่นอ่านไปแสดงผลต่อ
 *
 * Exit code: 0 = ไม่พบปัญหา, 1 = พบปัญหาอย่างน้อย 1 รายการ, 2 = เรียกใช้ผิด
 * อาร์กิวเมนต์ที่ไม่ขึ้นต้นด้วย -- ถือเป็น "ชื่อโดเมน" (ใส่ได้หลายตัว)
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (function_exists('proc_nice')) { @proc_nice(19); }
@exec('ionice -c3 -p ' . getmypid() . ' 2>/dev/null');

// ====== ค่าตั้งต้น ======
$BASE        = '/home';
$DO_PLUGINS  = false;
$DO_THEMES   = false;
$ONLY_ISSUES = false;
$AS_JSON     = false;
$DOMAINS     = [];   // แต่ละตัว = ['domain'=>..., 'user'=>... หรือ null]
$LIST_URL    = null;
// เกณฑ์ (ตรงกับสคริปต์หลัก)
$PLUGIN_TH   = 3;
$THEME_TH    = 10;
$CHILD_NAME  = 'blocksy-child';
$CHILD_MIN   = 2;
$PARENT_NAME = 'blocksy';
$PARENT_MIN  = 50;
$DO_ERRLOG   = true;
$LOG_DAYS    = 7;
$FRESH_HOURS = 6;      // error ใน N ชม. = ยังพังอยู่
$TZ          = 7;
$LOG_TAIL    = 262144; // อ่านท้าย log 256KB (เดิม 16KB น้อยไป — log ยุ่งๆ ย้อนได้ไม่กี่นาที)

// ====== อ่าน argument ======
global $argv;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--plugins')        { $DO_PLUGINS = true; }
    elseif ($a === '--themes')     { $DO_THEMES = true; }
    elseif ($a === '--only-issues'){ $ONLY_ISSUES = true; }
    elseif ($a === '--json')       { $AS_JSON = true; }
    elseif ($a === '--no-errorlog'){ $DO_ERRLOG = false; }
    elseif (strpos($a, '--base=') === 0)     { $BASE = substr($a, 7); }
    elseif (strpos($a, '--list=') === 0)     { $LIST_URL = substr($a, 7); }
    elseif (strpos($a, '--log-days=') === 0) { $LOG_DAYS = max(1,(int)substr($a, 11)); }
    elseif (strpos($a, '--') === 0) { fwrite(STDERR, "** ไม่รู้จัก flag: $a (ข้ามไป) **\n"); }
    else {
        // รูปแบบ "โดเมน" หรือ "โดเมน:บัญชี" (ระบุบัญชี = หาเจอทันที ไม่ต้องไล่ค้น)
        $dom = trim($a); $usr = null;
        if (strpos($dom, ':') !== false) {
            list($dom, $usr) = explode(':', $dom, 2);
            $dom = trim($dom);
            $usr = trim($usr);
            if ($usr === '') $usr = null;
        }
        if ($dom !== '') $DOMAINS[] = ['domain' => $dom, 'user' => $usr];
    }
}
if (!$DO_PLUGINS && !$DO_THEMES) { $DO_PLUGINS = true; $DO_THEMES = true; }

// ====== ดึงรายชื่อโดเมนจาก CSV (ถ้าระบุ --list) ======
if ($LIST_URL !== null) {
    fwrite(STDERR, "กำลังดึงรายชื่อโดเมนจาก: $LIST_URL\n");
    $csv = @file_get_contents($LIST_URL);
    if ($csv === false) {
        fwrite(STDERR, "** ดึง CSV ไม่สำเร็จ — ตรวจ URL หรือการเชื่อมต่อ **\n");
        exit(2);
    }
    // กัน BOM ที่บางโปรแกรมแอบใส่หัวไฟล์ (ทำให้คอลัมน์แรกชื่อเพี้ยน)
    if (substr($csv, 0, 3) === "\xEF\xBB\xBF") $csv = substr($csv, 3);
    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    $header = null;
    $di = 0; $ui = 1;
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $cols = str_getcsv($line, ',', '"', '\\');
        if ($header === null) {
            $header = array_map('strtolower', array_map('trim', $cols));
            $fd = array_search('domain', $header);
            $fu = array_search('cpanel_user', $header);
            if ($fd !== false) $di = $fd;
            if ($fu !== false) $ui = $fu;
            // ถ้าบรรทัดแรกไม่ใช่ header ให้ถือเป็นข้อมูลเลย
            if ($fd === false) {
                $dom = isset($cols[$di]) ? trim($cols[$di]) : '';
                $usr = isset($cols[$ui]) ? trim($cols[$ui]) : '';
                if ($dom !== '') $DOMAINS[] = ['domain' => $dom, 'user' => ($usr !== '' ? $usr : null)];
            }
            continue;
        }
        $dom = isset($cols[$di]) ? trim($cols[$di]) : '';
        if ($dom === '') continue;
        $usr = isset($cols[$ui]) ? trim($cols[$ui]) : '';
        $DOMAINS[] = ['domain' => $dom, 'user' => ($usr !== '' ? $usr : null)];
    }
    fwrite(STDERR, "ได้รายชื่อ " . count($DOMAINS) . " โดเมนจาก CSV\n\n");
}

// กันโดเมนซ้ำ (พิมพ์ซ้ำ หรือ CSV มีแถวซ้ำ)
$seen = [];
$uniq = [];
foreach ($DOMAINS as $d) {
    $k = $d['domain'] . '|' . ($d['user'] === null ? '' : $d['user']);
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    $uniq[] = $d;
}
$DOMAINS = $uniq;

if (count($DOMAINS) === 0) {
    fwrite(STDERR, "ใช้งาน: php domain-check.php -- <domain> [domain2 ...]\n");
    fwrite(STDERR, "        (ระบุบัญชีด้วยได้: <domain>:<cpanel_user> — หาเจอเร็วกว่า)\n");
    fwrite(STDERR, "    หรือ: php domain-check.php -- --list=<CSV_URL>\n");
    fwrite(STDERR, "ตัวอย่าง: php domain-check.php -- mcm5699.com kplus888.org:newkeydec2025\n");
    exit(2);
}

// ====== ฟังก์ชันช่วย ======

/** เติมช่องว่างท้ายข้อความตามความกว้างจอจริง (ภาษาไทยกว้างไม่เท่า byte ทำให้ printf เพี้ยน) */
function pad_col($s, $w) {
    $len = function_exists('mb_strwidth') ? mb_strwidth($s, 'UTF-8') : strlen($s);
    return $s . str_repeat(' ', max(1, $w - $len));
}

/** นับไฟล์ + ขนาดรวม ในการเดินรอบเดียว (เดิมเดิน 2 รอบ เปลืองแรงเครื่อง)
 *  โฟลเดอร์ที่อ่านสิทธิ์ไม่ได้จะถูกข้าม ไม่ทำให้สคริปต์ล้ม */
function scan_dir_stats($dir) {
    $n = 0; $size = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) {
            if ($f->isFile()) { $n++; $size += $f->getSize(); }
        }
    } catch (Exception $e) {
        // โฟลเดอร์เปิดไม่ได้ทั้งก้อน — คืนเท่าที่นับได้
    }
    return [$n, $size];
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

/** อ่าน error_log หนึ่งไฟล์ หา Fatal error ล่าสุดในช่วง $log_days วัน
 *  คืนค่า: ['time','msg','source','count'] หรือ null */
function read_one_errorlog($log, $log_days, $tail_bytes) {
    if (!is_file($log)) return null;
    $cutoff = time() - ($log_days * 86400);
    $size = @filesize($log);
    $fh = @fopen($log, 'rb');
    if (!$fh) return null;
    if ($size > $tail_bytes) fseek($fh, -$tail_bytes, SEEK_END);
    $tail = fread($fh, $tail_bytes);
    fclose($fh);
    if ($tail === false) return null;

    $months = ['Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,
               'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12];
    $last = null; $cnt = 0;
    foreach (explode("\n", $tail) as $ln) {
        if (strpos($ln, 'PHP Fatal error') === false) continue;
        if (preg_match('/\[(\d{2})-([A-Za-z]{3})-(\d{4})\s+(\d{2}):(\d{2}):(\d{2})/', $ln, $m)) {
            $mon = isset($months[$m[2]]) ? $months[$m[2]] : 1;
            $t = gmmktime((int)$m[4],(int)$m[5],(int)$m[6], $mon, (int)$m[1], (int)$m[3]);
            if ($t >= $cutoff) {
                $source = '';
                if (preg_match('#/(plugins|themes)/([^/]+)/#', $ln, $pm)) {
                    $source = "{$pm[1]}/{$pm[2]}";
                }
                $s = preg_replace('/^\[[^\]]+\]\s*/', '', $ln);
                if (preg_match('/(Uncaught .*?)(?: in |$)/', $s, $mm)) $s = $mm[1];
                if (strlen($s) > 100) $s = substr($s, 0, 100) . '...';
                $last = ['time'=>$t, 'msg'=>trim($s), 'source'=>$source];
                $cnt++;
            }
        }
    }
    if ($last === null) return null;
    $last['count'] = $cnt;
    return $last;
}

/** อ่าน error_log ของเว็บจากหลายตำแหน่งที่ cPanel ชอบวาง
 *  (เดิมดูแค่โคนเว็บ — พลาดเคส error_log อยู่ใน wp-admin/ หรือ wp-content/) */
function read_site_errorlog($site_dir, $log_days, $tail_bytes) {
    $cands = [
        "$site_dir/error_log",
        "$site_dir/wp-admin/error_log",
        "$site_dir/wp-content/error_log",
    ];
    $best = null; $total = 0;
    foreach ($cands as $log) {
        $r = read_one_errorlog($log, $log_days, $tail_bytes);
        if ($r === null) continue;
        $total += $r['count'];
        if ($best === null || $r['time'] > $best['time']) {
            $r['log'] = $log;
            $best = $r;
        }
    }
    if ($best === null) return null;
    $best['count'] = $total;
    return $best;
}

/** สแกนลึกหา wp-content ใต้ $root (ใช้เฉพาะเมื่อเส้นทางมาตรฐานไม่เจอ)
 *  โฟลเดอร์ที่ "ชื่อตรงโดเมนเป๊ะ" มาก่อนแบบ "มีคำว่าโดเมนปนอยู่" */
function deep_scan_wpcontent($root, $domain) {
    $exact = []; $loose = [];
    $stack = [[$root, 0]];
    $skip = ['node_modules','.git','cache','.cache','tmp','logs',
             'mail','etc','ssl','.cpanel','.cphorde','.softaculous','virtfs'];
    while ($stack) {
        list($dir, $depth) = array_pop($stack);
        $dh = @opendir($dir);
        if ($dh === false) continue;
        while (($e = readdir($dh)) !== false) {
            if ($e === '.' || $e === '..') continue;
            $p = "$dir/$e";
            if (!is_dir($p) || is_link($p)) continue;
            if ($e === 'wp-content') {
                if (in_array($domain, explode('/', $dir), true)) $exact[] = $p;
                elseif (strpos($p, $domain) !== false) $loose[] = $p;
                continue;
            }
            if (in_array($e, $skip, true)) continue;
            if ($depth + 1 <= 6) { $stack[] = [$p, $depth + 1]; }
        }
        closedir($dh);
    }
    return count($exact) > 0 ? $exact : $loose;
}

/** หา wp-content ของโดเมนที่ระบุ
 *  ขั้น 1 (เร็ว): เส้นทางมาตรฐานเซิร์ฟเวอร์เรา /home/<บัญชี>/<โดเมน>/wp-content
 *  ขั้น 2 (ช้า): ไล่ค้นทีละบัญชี พร้อมแสดงความคืบหน้าทางจอ (STDERR)
 *               เจอแล้วหยุดทันที ไม่ค้นบัญชีที่เหลือต่อให้เสียเวลา */
function find_domain_wpcontent($base, $domain, $user = null) {
    if ($user !== null && is_dir("$base/$user")) {
        $p = "$base/$user/$domain/wp-content";
        if (is_dir($p)) return [$p];
        fwrite(STDERR, "  ไม่เจอที่เส้นทางมาตรฐาน — กำลังค้นทั้งบัญชี $user ...\n");
        return deep_scan_wpcontent("$base/$user", $domain);
    }

    $fast = glob("$base/*/$domain/wp-content", GLOB_ONLYDIR) ?: [];
    if (count($fast) > 0) return $fast;

    $accounts = glob("$base/*", GLOB_ONLYDIR) ?: [];
    $n = count($accounts);
    $i = 0;
    $found = [];
    foreach ($accounts as $acc) {
        $i++;
        fwrite(STDERR, "\r  กำลังค้นหา $domain : บัญชีที่ $i/$n (" . basename($acc) . ")" . str_repeat(' ', 15));
        $hit = deep_scan_wpcontent($acc, $domain);
        if (count($hit) > 0) { $found = $hit; break; }
    }
    fwrite(STDERR, "\r" . str_repeat(' ', 79) . "\r");
    return $found;
}

// ====== ตรวจแต่ละโดเมน ======
$REPORT = [];   // เก็บผลทั้งหมดไว้สำหรับ --json
$ISSUES = 0;
$BAD_DOMAINS = [];

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

    $drep = ['domain'=>$domain, 'user'=>$user, 'found'=>false, 'sites'=>[]];

    $wpcs = find_domain_wpcontent($BASE, $domain, $user);
    if (count($wpcs) === 0) {
        echo "  ** ไม่พบเว็บ WordPress ของโดเมนนี้ (อาจไม่มี wp-content หรือพิมพ์ชื่อผิด) **\n\n";
        $ISSUES++;
        $BAD_DOMAINS[$domain] = true;
        $REPORT[] = $drep;
        continue;
    }
    $drep['found'] = true;

    foreach ($wpcs as $wpc) {
        $site = preg_replace('#/wp-content$#', '', $wpc);
        echo "\n  ที่ตั้ง: $site\n";
        $srep = ['path'=>$site, 'plugins'=>[], 'themes'=>[], 'errorlog'=>null];

        $types = [];
        if ($DO_PLUGINS) $types['plugins'] = 'plugin';
        if ($DO_THEMES)  $types['themes']  = 'theme';

        foreach ($types as $sub => $type) {
            $container = "$wpc/$sub";
            if (!is_dir($container)) {
                echo "    [" . strtoupper($type) . "] ไม่มีโฟลเดอร์ $sub\n";
                $ISSUES++;
                $BAD_DOMAINS[$domain] = true;
                continue;
            }
            $entries = glob("$container/*", GLOB_ONLYDIR) ?: [];
            sort($entries);
            echo "    [" . strtoupper($type) . "]\n";
            echo "    " . pad_col('สถานะ',14) . pad_col('ไฟล์',7) . pad_col('ขนาด',9) . "ชื่อ\n";

            $found_issue = false;
            foreach ($entries as $d) {
                $name = basename($d);
                list($fcount, $bytes) = scan_dir_stats($d);
                $size = human_size($bytes);
                $missing = [];

                if ($type === 'theme') {
                    if ($fcount === 0) { $status = 'ว่าง!'; $code = 'empty'; }
                    elseif ($name === $PARENT_NAME) {
                        $missing = missing_core_files($d);
                        if (count($missing) > 0) { $status = 'ไฟล์หลักขาด'; $code = 'missing_core'; }
                        elseif ($fcount <= $PARENT_MIN) { $status = 'สงสัย'; $code = 'suspect'; }
                        else { $status = 'ปกติ'; $code = 'ok'; }
                    }
                    elseif ($name === $CHILD_NAME) {
                        if ($fcount <= $CHILD_MIN) { $status = 'สงสัย'; $code = 'suspect'; }
                        elseif (!has_header($d,'theme')) { $status = 'ไม่มีหลัก'; $code = 'no_header'; }
                        else { $status = 'ปกติ'; $code = 'ok'; }
                    }
                    elseif ($fcount <= $THEME_TH) { $status = 'สงสัย'; $code = 'suspect'; }
                    elseif (!has_header($d,'theme')) { $status = 'ไม่มีหลัก'; $code = 'no_header'; }
                    else { $status = 'ปกติ'; $code = 'ok'; }
                } else {
                    if ($fcount === 0) { $status = 'ว่าง!'; $code = 'empty'; }
                    elseif ($fcount <= $PLUGIN_TH) { $status = 'สงสัย'; $code = 'suspect'; }
                    elseif (!has_header($d,'plugin')) { $status = 'ไม่มีหลัก'; $code = 'no_header'; }
                    else { $status = 'ปกติ'; $code = 'ok'; }
                }

                $item = ['name'=>$name, 'files'=>$fcount, 'bytes'=>$bytes, 'status'=>$code];
                if (count($missing) > 0) $item['missing'] = $missing;
                $srep[$sub][] = $item;

                if ($code !== 'ok') {
                    $found_issue = true;
                    $ISSUES++;
                    $BAD_DOMAINS[$domain] = true;
                }
                if ($ONLY_ISSUES && $code === 'ok') continue;
                echo "    " . pad_col($status,14) . pad_col((string)$fcount,7) . pad_col($size,9) . $name . "\n";
                if (count($missing) > 0) {
                    echo "    " . pad_col('',14) . "ขาด: " . implode(', ', $missing) . "\n";
                }
            }
            if ($ONLY_ISSUES && !$found_issue) echo "    (ไม่พบปัญหาจากการนับไฟล์)\n";
        }

        // อ่าน error_log (จับเคสไฟล์ลึกหายที่นับไฟล์มองไม่เห็น)
        if ($DO_ERRLOG) {
            $err = read_site_errorlog($site, $LOG_DAYS, $LOG_TAIL);
            echo "    [ERROR LOG]\n";
            if ($err === null) {
                echo "    ไม่พบ Fatal error ใน $LOG_DAYS วันล่าสุด (หรือไม่มีไฟล์ error_log)\n";
            } else {
                $age_h = (time() - $err['time']) / 3600;
                $fresh = ($age_h <= $FRESH_HOURS);
                $flag = $fresh ? '[ยังพังอยู่]' : '[อาจแก้แล้ว]';
                $thai = gmdate('Y-m-d H:i', $err['time'] + $TZ*3600) . " (+$TZ)";
                echo "    $flag  เวลาล่าสุด: $thai  (เกิด {$err['count']}+ ครั้ง)\n";
                if (!empty($err['source'])) echo "    ต้นเหตุ: {$err['source']}\n";
                echo "    สาเหตุ: {$err['msg']}\n";
                echo "    ไฟล์ log: {$err['log']}\n";
                $srep['errorlog'] = [
                    'fresh'  => $fresh,
                    'time'   => gmdate('c', $err['time']),
                    'count'  => $err['count'],
                    'source' => $err['source'],
                    'msg'    => $err['msg'],
                    'log'    => $err['log'],
                ];
                if ($fresh) {
                    $ISSUES++;
                    $BAD_DOMAINS[$domain] = true;
                }
            }
        }
        echo "\n";
        $drep['sites'][] = $srep;
    }
    $REPORT[] = $drep;
}

// ====== สรุปท้ายรายงาน ======
echo "=======================================================\n";
echo " สรุปผล\n";
echo "=======================================================\n";
echo " โดเมนที่ตรวจ : " . count($DOMAINS) . "\n";
if ($ISSUES === 0) {
    echo " ผลรวม       : ไม่พบปัญหา ทุกอย่างปกติ\n";
} else {
    echo " พบปัญหา     : $ISSUES รายการ ใน " . count($BAD_DOMAINS) . " โดเมน\n";
    echo " โดเมนมีปัญหา: " . implode(', ', array_keys($BAD_DOMAINS)) . "\n";
}
echo "หมายเหตุ: สคริปต์นี้อ่านอย่างเดียว ไม่แก้ไขไฟล์ใด ๆ\n";

if ($AS_JSON) {
    $out = [
        'time'    => date('c'),
        'checked' => count($DOMAINS),
        'issues'  => $ISSUES,
        'bad_domains' => array_keys($BAD_DOMAINS),
        'domains' => $REPORT,
    ];
    echo "\n===JSON===\n";
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
}

exit($ISSUES > 0 ? 1 : 0);
