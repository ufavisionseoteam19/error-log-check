<?php
/**
 * error-log-check.php — ไล่อ่าน error_log ทุกเว็บ หา "เว็บที่พังจริง" (Fatal error)
 *                       จับเว็บที่ล่มเงียบ ๆ ที่การนับไฟล์มองไม่เห็น
 *
 * อ่านอย่างเดียว 100% — ไม่ลบ ไม่แก้ ไม่ย้ายไฟล์ใด ๆ
 *
 * รันตรงจาก GitHub:
 *   URL='https://raw.githubusercontent.com/ufavisionseoteam19/error-log-check/main/error-log-check.php'
 *   curl -s "$URL?v=$(date +%s)" | php                      # 7 วันล่าสุด, Fatal error
 *   curl -s "$URL?v=$(date +%s)" | php -- --days=3          # เปลี่ยนช่วงวัน
 *   curl -s "$URL?v=$(date +%s)" | php -- --user=cloudwa3   # เฉพาะบัญชี
 *   curl -s "$URL?v=$(date +%s)" | php -- --warnings        # รวม Warning ด้วย
 *
 * Flags:
 *   --days=N      ดู error_log ที่ถูกแก้ใน N วันล่าสุด (ค่าเริ่มต้น = 7)
 *   --user=NAME   เฉพาะบัญชีเดียว (ค่าเริ่มต้น = ทุกบัญชี)
 *   --base=PATH   โฟลเดอร์ฐาน (ค่าเริ่มต้น = /home)
 *   --warnings    รวม PHP Warning/Deprecated ด้วย (ค่าเริ่มต้น = แค่ Fatal error)
 *   --tail=N      อ่านท้ายไฟล์กี่ KB (ค่าเริ่มต้น = 16 KB ต่อไฟล์)
 *   --tz=N        เขตเวลา +N สำหรับแปลงแสดงผล (ค่าเริ่มต้น = 7 = ไทย)
 *   --csv         ออกผลเป็น CSV
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
if (function_exists('proc_nice')) { @proc_nice(19); }
@exec('ionice -c3 -p ' . getmypid() . ' 2>/dev/null');

// ====== ค่าตั้งต้น ======
$BASE     = '/home';
$ONLY_USER= null;
$DAYS     = 7;
$WARNINGS = false;
$TAIL_KB  = 16;
$TZ       = 7;       // ไทย UTC+7
$AS_CSV   = false;
$PROGRESS = true;

global $argv;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--days=') === 0)      { $DAYS = max(1, (int)substr($a, 7)); }
    elseif (strpos($a, '--user=') === 0)  { $ONLY_USER = substr($a, 7); }
    elseif (strpos($a, '--base=') === 0)  { $BASE = substr($a, 7); }
    elseif (strpos($a, '--tail=') === 0)  { $TAIL_KB = max(1, (int)substr($a, 7)); }
    elseif (strpos($a, '--tz=') === 0)    { $TZ = (int)substr($a, 5); }
    elseif ($a === '--warnings')          { $WARNINGS = true; }
    elseif ($a === '--csv')               { $AS_CSV = true; }
    elseif ($a === '--no-progress')       { $PROGRESS = false; }
}
if ($AS_CSV) { $PROGRESS = false; }

// ====== หา error_log ที่ถูกแก้ใน N วันล่าสุด ======
$scan_root = $ONLY_USER ? "$BASE/$ONLY_USER" : $BASE;
$cutoff = time() - ($DAYS * 86400);

if ($PROGRESS) fwrite(STDERR, "กำลังค้นหา error_log ที่ถูกแก้ใน $DAYS วันล่าสุด...\n");

// ใช้ find เร็วกว่าไล่ PHP เอง
$cmd = "find " . escapeshellarg($scan_root) . " -maxdepth 3 -name error_log -type f -mtime -$DAYS 2>/dev/null";
$logs = array_filter(explode("\n", shell_exec($cmd) ?: ''));
sort($logs);

if ($PROGRESS) fwrite(STDERR, "พบ error_log " . count($logs) . " ไฟล์ (ในช่วง $DAYS วัน)\n\n");

/** อ่านท้ายไฟล์ N bytes (เร็ว ไม่อ่านทั้งไฟล์ใหญ่) */
function read_tail($file, $kb) {
    $bytes = $kb * 1024;
    $size = @filesize($file);
    if ($size === false) return '';
    $fh = @fopen($file, 'rb');
    if (!$fh) return '';
    if ($size > $bytes) fseek($fh, -$bytes, SEEK_END);
    $data = fread($fh, $bytes);
    fclose($fh);
    return $data;
}

/** แปลง timestamp ใน log [30-May-2026 20:04:45 UTC] เป็น epoch */
function parse_log_time($line) {
    if (preg_match('/\[(\d{2})-([A-Za-z]{3})-(\d{4})\s+(\d{2}):(\d{2}):(\d{2})/', $line, $m)) {
        $months = ['Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,
                   'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12];
        $mon = $months[$m[2]] ?? 1;
        return gmmktime((int)$m[4],(int)$m[5],(int)$m[6],$mon,(int)$m[1],(int)$m[3]);
    }
    return null;
}

/** ดึงสาเหตุสั้น ๆ จากบรรทัด Fatal error */
function extract_cause($line) {
    // ตัด timestamp ออก
    $s = preg_replace('/^\[[^\]]+\]\s*/', '', $line);
    // ดึงส่วนสำคัญ: ปลั๊กอิน/ธีมที่เกี่ยว
    if (preg_match('#/(plugins|themes)/([^/]+)/#', $line, $pm)) {
        $where = "{$pm[1]}/{$pm[2]}";
    } else {
        $where = 'core/อื่นๆ';
    }
    // ข้อความ error ย่อ
    $msg = $s;
    if (preg_match('/(Uncaught Error:.*?)(?: in |$)/', $s, $mm)) $msg = $mm[1];
    elseif (preg_match('/(Cannot .*?)(?: in |$)/', $s, $mm)) $msg = $mm[1];
    $msg = trim($msg);
    if (strlen($msg) > 90) $msg = substr($msg, 0, 90) . '...';
    return [$where, $msg];
}

// ====== ตัวนับ ======
$results = [];   // site => ['time'=>epoch, 'where'=>..., 'msg'=>..., 'count'=>N]
$scanned = 0;

$pattern = $WARNINGS ? '/PHP (Fatal error|Warning|Deprecated)/' : '/PHP Fatal error/';

foreach ($logs as $log) {
    $scanned++;
    if ($PROGRESS && $scanned % 200 === 0) {
        fwrite(STDERR, "  ...ตรวจแล้ว $scanned/" . count($logs) . " ไฟล์\r");
    }
    $tail = read_tail($log, $TAIL_KB);
    if ($tail === '') continue;

    $lines = explode("\n", $tail);
    $last_match = null; $cnt = 0;
    foreach ($lines as $ln) {
        if (preg_match($pattern, $ln)) {
            $t = parse_log_time($ln);
            // นับเฉพาะที่อยู่ในช่วง N วัน
            if ($t !== null && $t >= $cutoff) {
                $last_match = $ln;
                $cnt++;
            }
        }
    }
    if ($last_match === null) continue;

    // site = โฟลเดอร์ที่มี error_log
    $site = dirname($log);
    $t = parse_log_time($last_match);
    list($where, $msg) = extract_cause($last_match);
    $results[$site] = ['time'=>$t, 'where'=>$where, 'msg'=>$msg, 'count'=>$cnt];
}
if ($PROGRESS) fwrite(STDERR, "                                        \r");

// เรียงตามเวลาล่าสุด (ใหม่สุดก่อน)
uasort($results, function($a,$b){ return $b['time'] <=> $a['time']; });

// ====== แสดงผล ======
function fmt_thai($epoch, $tz) {
    if ($epoch === null) return '-';
    return gmdate('Y-m-d H:i', $epoch + $tz*3600) . " (+$tz)";
}

if ($AS_CSV) {
    echo "site,last_error_thai,where,count,message\n";
    foreach ($results as $site=>$r) {
        echo "\"$site\",\"".fmt_thai($r['time'],$TZ)."\",\"{$r['where']}\",{$r['count']},\"".str_replace('"',"'",$r['msg'])."\"\n";
    }
    exit(0);
}

echo "=======================================================\n";
echo " WordPress Error Log Check — หาเว็บที่พังจริง (read-only)\n";
echo "=======================================================\n";
echo " วันเวลา      : " . gmdate('Y-m-d H:i', time()+$TZ*3600) . " (เวลาไทย)\n";
echo " เครื่อง       : " . php_uname('n') . "\n";
echo " ขอบเขต       : " . ($ONLY_USER ? "บัญชี '$ONLY_USER'" : "ทุกบัญชีใน $BASE") . "\n";
echo " ช่วงเวลา     : $DAYS วันล่าสุด\n";
echo " จับ          : " . ($WARNINGS ? 'Fatal error + Warning' : 'Fatal error เท่านั้น') . "\n";
echo " error_log    : " . count($logs) . " ไฟล์\n";
echo " เว็บที่พบปัญหา: " . count($results) . " เว็บ\n\n";

if (count($results) === 0) {
    echo " ไม่พบเว็บที่มี Fatal error ในช่วง $DAYS วันล่าสุด\n";
    exit(0);
}

// จัดกลุ่มตามสาเหตุ (where) เรียงกลุ่มที่กระทบมากสุด
$groups = [];
foreach ($results as $site=>$r) { $groups[$r['where']][] = ['site'=>$site] + $r; }
uasort($groups, function($a,$b){ return count($b)-count($a); });

foreach ($groups as $where=>$items) {
    echo "** [$where] กระทบ " . count($items) . " เว็บ **\n";
    foreach ($items as $it) {
        echo "  ┌─ {$it['site']}\n";
        echo "  │   เวลาล่าสุด : " . fmt_thai($it['time'],$TZ) . "  (เกิด {$it['count']}+ ครั้ง)\n";
        echo "  │   สาเหตุ     : {$it['msg']}\n";
        echo "  └──────────────────────────────\n";
    }
    echo "\n";
}

echo " หมายเหตุ: เวลาแปลงเป็นเวลาไทย (UTC+$TZ) | อ่านท้าย log $TAIL_KB KB ต่อไฟล์\n";
echo " สคริปต์นี้อ่านอย่างเดียว ไม่แก้ไขไฟล์ใด ๆ\n";
