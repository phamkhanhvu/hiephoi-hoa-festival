<?php
// API nội bộ truyền thông. Ai cũng đọc được; chỉ admin đã đăng nhập mới lưu được.
declare(strict_types=1);

$base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
session_name('ttnb_sess'); // tên và đường dẫn riêng để không dùng chung phiên với ứng dụng khác trên cùng tên miền
session_set_cookie_params(['lifetime' => 0, 'path' => $base, 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('Asia/Ho_Chi_Minh');

$dir = __DIR__;
$cfgFile = $dir . '/config.local.php';
$storeDir = $dir . '/data';
$storeFile = $storeDir . '/store.json';
$seedFile = $dir . '/seed.json';
$seedBaseFile = $dir . '/seed-base.json';        // bản gốc trước các lần sửa trong mã nguồn
$seedAppliedFile = $storeDir . '/seed-applied.json'; // bản seed đã được gộp vào dữ liệu đang chạy
$failFile = $storeDir . '/login-fails.json';
$STATUSES = ['Chưa bắt đầu', 'Đang làm', 'Chờ duyệt', 'Bị chặn', 'Hoàn tất'];
$PHASES = ['Trước', 'Trong', 'Sau'];
$FIELDS = ['phase', 'start', 'end', 'task', 'owner', 'output', 'approve', 'note', 'fb', 'short', 'live', 'group', 'status'];

function out($arr, int $code = 200): void {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400, array $extra = []): void { out(['ok' => false, 'error' => $msg] + $extra, $code); }
function body(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw ?: '[]', true);
    return is_array($j) ? $j : [];
}
function readJson(string $f): ?array {
    if (!is_file($f)) return null;
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function writeJson(string $f, array $d): bool {
    $tmp = $f . '.tmp';
    return file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false && rename($tmp, $f);
}
function clean($v, int $max): string {
    $s = is_scalar($v) ? trim((string)$v) : '';
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    return mb_substr($s, 0, $max, 'UTF-8');
}
function cleanSubtasks($list): array {
    global $STATUSES;
    $out = [];
    if (!is_array($list)) return $out;
    foreach ($list as $s) {
        if (!is_array($s)) $s = ['text' => $s];
        $text = clean($s['text'] ?? '', 300);
        if ($text === '') continue;
        // dữ liệu cũ dùng cờ "done" thay cho trạng thái
        $status = (string)($s['status'] ?? (!empty($s['done']) ? 'Hoàn tất' : 'Chưa bắt đầu'));
        if (!in_array($status, $STATUSES, true)) $status = 'Chưa bắt đầu';
        $out[] = ['text' => $text, 'owner' => clean($s['owner'] ?? '', 200), 'status' => $status];
        if (count($out) >= 50) break;
    }
    return $out;
}
function validDate($v): bool {
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}
function normalize(?array $d): array {
    if (!is_array($d)) $d = [];
    foreach (['tasks', 'kpi', 'open', 'log'] as $k) $d[$k] = is_array($d[$k] ?? null) ? array_values($d[$k]) : [];
    $tasks = [];
    foreach ($d['tasks'] as $t) {
        if (!is_array($t)) continue;
        if (($t['status'] ?? '') === 'Chưa cập nhật' || !isset($t['status'])) $t['status'] = 'Chưa bắt đầu';
        $t['statusAt'] = (string)($t['statusAt'] ?? '');
        $t['subtasks'] = cleanSubtasks($t['subtasks'] ?? []);
        $tasks[] = $t;
    }
    $d['tasks'] = $tasks;
    return $d;
}
function logLine(array &$log, string $now, string $code, string $task, string $from, string $to): void {
    array_unshift($log, ['at' => $now, 'code' => $code, 'task' => mb_substr($task, 0, 80, 'UTF-8'), 'from' => $from, 'to' => $to]);
}
function withLock(string $storeDir, callable $fn) {
    if (!is_dir($storeDir)) @mkdir($storeDir, 0755, true);
    $h = @fopen($storeDir . '/.lock', 'c');
    if ($h) flock($h, LOCK_EX);
    try { return $fn(); } finally { if ($h) { flock($h, LOCK_UN); fclose($h); } }
}

/**
 * Gộp các thay đổi làm trong mã nguồn (seed.json) vào dữ liệu đang chạy.
 * So sánh seed mới với bản seed đã gộp lần trước: chỉ trường nào đổi trong mã nguồn mới ghi đè,
 * các chỉnh sửa của admin trên trang được giữ nguyên.
 */
function syncSeed(array $store): array {
    global $seedFile, $seedBaseFile, $seedAppliedFile, $storeFile, $FIELDS;
    $seed = readJson($seedFile);
    if ($seed === null) return $store;
    $applied = readJson($seedAppliedFile) ?? readJson($seedBaseFile);
    if ($applied === null) { writeJson($seedAppliedFile, $seed); return $store; }
    $pick = function (array $d): array { return [$d['tasks'] ?? [], $d['kpi'] ?? [], $d['open'] ?? []]; };
    if ($pick($seed) == $pick($applied)) {
        if (!is_file($seedAppliedFile)) writeJson($seedAppliedFile, $seed);
        return $store;
    }
    $now = date('c');
    $baseMap = [];
    foreach (($applied['tasks'] ?? []) as $t) if (is_array($t)) $baseMap[(string)($t['code'] ?? '')] = $t;
    $idx = [];
    foreach ($store['tasks'] as $i => $t) $idx[(string)($t['code'] ?? '')] = $i;
    foreach (($seed['tasks'] ?? []) as $st) {
        if (!is_array($st)) continue;
        $code = (string)($st['code'] ?? '');
        $inBase = isset($baseMap[$code]);
        $inStore = isset($idx[$code]);
        if (!$inBase && !$inStore) { // đầu việc mới thêm trong mã nguồn
            $st['statusAt'] = $now;
            $st['subtasks'] = cleanSubtasks($st['subtasks'] ?? []);
            $store['tasks'][] = $st;
            logLine($store['log'], $now, $code, (string)($st['task'] ?? ''), '(mới)', (string)($st['status'] ?? ''));
        } elseif ($inBase && $inStore) {
            $i = $idx[$code];
            foreach ($FIELDS as $f) {
                if (($st[$f] ?? null) === ($baseMap[$code][$f] ?? null)) continue;
                if ($f === 'status' && ($store['tasks'][$i]['status'] ?? '') !== ($st['status'] ?? '')) {
                    logLine($store['log'], $now, $code, (string)($st['task'] ?? ''), (string)($store['tasks'][$i]['status'] ?? ''), (string)$st['status']);
                    $store['tasks'][$i]['statusAt'] = $now;
                }
                $store['tasks'][$i][$f] = $st[$f] ?? '';
            }
        }
        // có trong bản gốc nhưng admin đã xóa, hoặc admin tự tạo cùng mã: giữ nguyên dữ liệu đang chạy
    }
    foreach (['kpi', 'open'] as $k) {
        if (($seed[$k] ?? []) != ($applied[$k] ?? []) && $store[$k] == ($applied[$k] ?? [])) $store[$k] = $seed[$k] ?? [];
    }
    $store['log'] = array_slice($store['log'], 0, 200);
    $store['updatedAt'] = $now;
    if (writeJson($storeFile, $store)) writeJson($seedAppliedFile, $seed);
    return $store;
}
function loadData(): array {
    global $storeFile, $seedFile, $storeDir;
    if (!is_file($storeFile)) return normalize(readJson($seedFile));
    return withLock($storeDir, function () use ($storeFile) {
        $d = readJson($storeFile);
        if ($d === null) fail('Tệp dữ liệu trên máy chủ bị lỗi. Vui lòng báo quản trị.', 500);
        return syncSeed(normalize($d));
    });
}
function needPost(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('Yêu cầu không hợp lệ.', 405);
}

$action = $_GET['action'] ?? 'state';
$isAdmin = !empty($_SESSION['admin']);

if ($action === 'state') {
    out(['ok' => true, 'loggedIn' => $isAdmin, 'csrf' => $isAdmin ? $_SESSION['csrf'] : null, 'data' => loadData()]);
}

if ($action === 'login') {
    needPost();
    if (!is_file($cfgFile)) fail('Chưa cấu hình tài khoản admin.', 503);
    // chặn dò mật khẩu: tối đa 8 lần sai trong 15 phút cho mỗi địa chỉ
    if (!is_dir($storeDir)) @mkdir($storeDir, 0755, true);
    $ipKey = substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16);
    $fails = readJson($failFile) ?? [];
    $t = time();
    foreach ($fails as $k => $v) if (!is_array($v) || ($v['t'] ?? 0) < $t - 900) unset($fails[$k]);
    if (($fails[$ipKey]['n'] ?? 0) >= 8) fail('Sai quá nhiều lần. Vui lòng thử lại sau 15 phút.', 429);
    $cfg = require $cfgFile;
    $b = body();
    $okUser = hash_equals((string)($cfg['user'] ?? ''), (string)($b['user'] ?? ''));
    $okPass = !empty($cfg['hash']) && password_verify((string)($b['pass'] ?? ''), (string)$cfg['hash']);
    if (!($okUser && $okPass)) {
        $fails[$ipKey] = ['n' => ($fails[$ipKey]['n'] ?? 0) + 1, 't' => $fails[$ipKey]['t'] ?? $t];
        writeJson($failFile, $fails);
        usleep(700000);
        fail('Sai tài khoản hoặc mật khẩu.', 401);
    }
    unset($fails[$ipKey]);
    writeJson($failFile, $fails);
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'logout') {
    needPost();
    $_SESSION = [];
    session_destroy();
    out(['ok' => true]);
}

if ($action === 'save') {
    needPost();
    if (!$isAdmin) fail('Cần đăng nhập admin.', 401, ['code' => 'auth']);
    $b = body();
    if (!hash_equals((string)$_SESSION['csrf'], (string)($b['csrf'] ?? ''))) fail('Phiên làm việc đã hết hạn. Vui lòng đăng nhập lại.', 403, ['code' => 'auth']);
    $in = $b['data'] ?? null;
    if (!is_array($in) || !isset($in['tasks']) || !is_array($in['tasks'])) fail('Dữ liệu không hợp lệ.');
    if (count($in['tasks']) > 100) fail('Quá nhiều đầu việc.');

    $tasks = [];
    $seen = [];
    foreach ($in['tasks'] as $t) {
        if (!is_array($t)) continue;
        $code = clean($t['code'] ?? '', 12);
        $label = $code !== '' ? $code : 'đầu việc chưa có mã';
        $phase = (string)($t['phase'] ?? '');
        $status = (string)($t['status'] ?? 'Chưa bắt đầu');
        if ($code === '') fail('Mỗi đầu việc cần có mã.');
        if (isset($seen[$code])) fail("Mã $code bị trùng. Mỗi đầu việc cần một mã riêng.");
        $seen[$code] = true;
        if (clean($t['task'] ?? '', 600) === '') fail("$label: chưa có nội dung đầu việc.");
        if (!in_array($phase, $PHASES, true)) fail("$label: giai đoạn không hợp lệ.");
        if (!in_array($status, $STATUSES, true)) fail("$label: trạng thái không hợp lệ.");
        if (!validDate($t['start'] ?? '') || !validDate($t['end'] ?? '')) fail("$label: ngày không hợp lệ.");
        if ($t['start'] > $t['end']) fail("$label: ngày bắt đầu đang sau hạn.");
        $tasks[] = [
            'code' => $code,
            'phase' => $phase,
            'start' => (string)$t['start'],
            'end' => (string)$t['end'],
            'task' => clean($t['task'] ?? '', 600),
            'owner' => clean($t['owner'] ?? '', 200),
            'output' => clean($t['output'] ?? '', 300),
            'approve' => clean($t['approve'] ?? '', 200),
            'note' => clean($t['note'] ?? '', 1000),
            'fb' => max(0, min(999, (int)($t['fb'] ?? 0))),
            'short' => max(0, min(999, (int)($t['short'] ?? 0))),
            'live' => max(0, min(999, (int)($t['live'] ?? 0))),
            'group' => clean($t['group'] ?? '', 120),
            'status' => $status,
            'statusAt' => '',
            'subtasks' => cleanSubtasks($t['subtasks'] ?? []),
        ];
    }
    $kpi = [];
    foreach (($in['kpi'] ?? []) as $r) {
        if (!is_array($r)) continue;
        $row = [clean($r[0] ?? '', 200), clean($r[1] ?? '', 120), clean($r[2] ?? '', 120), clean($r[3] ?? '', 400)];
        if (implode('', $row) === '') continue;
        $kpi[] = $row;
        if (count($kpi) >= 60) break;
    }
    $open = [];
    foreach (($in['open'] ?? []) as $o) {
        $s = clean($o, 400);
        if ($s !== '') $open[] = $s;
        if (count($open) >= 60) break;
    }

    $result = withLock($storeDir, function () use ($tasks, $kpi, $open, $b, $storeFile, $seedFile, $seedAppliedFile) {
        $hasStore = is_file($storeFile);
        $prev = $hasStore ? readJson($storeFile) : readJson($seedFile);
        if ($hasStore && $prev === null) fail('Tệp dữ liệu trên máy chủ bị lỗi. Vui lòng báo quản trị.', 500);
        $prev = normalize($prev);
        if ($hasStore) $prev = syncSeed($prev);
        // chống ghi đè: bản người dùng đang sửa phải là bản mới nhất
        if ((string)($b['base'] ?? '') !== (string)($prev['updatedAt'] ?? '')) {
            fail('Dữ liệu đã được thay đổi ở nơi khác.', 409, ['code' => 'conflict', 'data' => $prev]);
        }
        $prevMap = [];
        foreach ($prev['tasks'] as $pt) $prevMap[(string)$pt['code']] = $pt;
        $now = date('c');
        $log = $prev['log'];
        $newCodes = [];
        foreach ($tasks as &$t) {
            $newCodes[$t['code']] = true;
            $old = $prevMap[$t['code']] ?? null;
            if ($old === null) {
                $t['statusAt'] = $now;
                logLine($log, $now, $t['code'], $t['task'], '(mới)', $t['status']);
                continue;
            }
            if ($old['status'] !== $t['status']) {
                $t['statusAt'] = $now;
                logLine($log, $now, $t['code'], $t['task'], $old['status'], $t['status']);
            } else {
                $t['statusAt'] = $old['statusAt'] ?? '';
            }
            // nhật ký đổi trạng thái đầu mục con (đối chiếu theo nội dung)
            $oldSub = [];
            foreach ($old['subtasks'] as $s) $oldSub[$s['text']] = $s['status'];
            foreach ($t['subtasks'] as $s) {
                if (isset($oldSub[$s['text']]) && $oldSub[$s['text']] !== $s['status']) {
                    logLine($log, $now, $t['code'], 'Đầu mục: ' . $s['text'], $oldSub[$s['text']], $s['status']);
                }
            }
        }
        unset($t);
        foreach ($prev['tasks'] as $pt) {
            if (!isset($newCodes[(string)$pt['code']])) logLine($log, $now, (string)$pt['code'], (string)$pt['task'], (string)$pt['status'], '(đã xóa)');
        }
        $store = ['tasks' => $tasks, 'kpi' => $kpi, 'open' => $open, 'log' => array_slice($log, 0, 200), 'updatedAt' => $now];
        if (!writeJson($storeFile, $store)) fail('Không lưu được. Kiểm tra quyền ghi thư mục noi-bo/data.', 500);
        if (!$hasStore && ($seed = readJson($seedFile)) !== null) writeJson($seedAppliedFile, $seed);
        return $store;
    });
    out(['ok' => true, 'data' => $result]);
}

fail('Yêu cầu không hợp lệ.', 404);
