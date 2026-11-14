<?php
// API nội bộ truyền thông. Ai cũng đọc được; chỉ admin đã đăng nhập mới lưu được.
declare(strict_types=1);
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$dir = __DIR__;
$cfgFile = $dir . '/config.local.php';
$storeDir = $dir . '/data';
$storeFile = $storeDir . '/store.json';
$seedFile = $dir . '/seed.json';
$STATUSES = ['Chưa bắt đầu', 'Đang làm', 'Chờ duyệt', 'Bị chặn', 'Hoàn tất'];
$PHASES = ['Trước', 'Trong', 'Sau'];

function out($arr, int $code = 200): void {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): void { out(['ok' => false, 'error' => $msg], $code); }
function body(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw ?: '[]', true);
    return is_array($j) ? $j : [];
}
function loadData(string $storeFile, string $seedFile): array {
    $f = is_file($storeFile) ? $storeFile : $seedFile;
    $d = json_decode((string)file_get_contents($f), true);
    if (!is_array($d)) $d = ['tasks' => [], 'kpi' => [], 'open' => []];
    $d['log'] = is_array($d['log'] ?? null) ? $d['log'] : [];
    foreach ($d['tasks'] as &$t) {
        if (($t['status'] ?? '') === 'Chưa cập nhật') $t['status'] = 'Chưa bắt đầu';
        $t['statusAt'] = $t['statusAt'] ?? '';
    }
    unset($t);
    return $d;
}
function clean($v, int $max): string {
    $s = trim((string)$v);
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    return mb_substr($s, 0, $max, 'UTF-8');
}
function validDate($v): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v); }

$action = $_GET['action'] ?? 'state';
$isAdmin = !empty($_SESSION['admin']);

if ($action === 'state') {
    out([
        'ok' => true,
        'loggedIn' => $isAdmin,
        'csrf' => $isAdmin ? $_SESSION['csrf'] : null,
        'data' => loadData($storeFile, $seedFile),
    ]);
}

if ($action === 'login') {
    if (!is_file($cfgFile)) fail('Chưa cấu hình tài khoản admin.', 503);
    $cfg = require $cfgFile;
    $b = body();
    $user = (string)($b['user'] ?? '');
    $pass = (string)($b['pass'] ?? '');
    $okUser = hash_equals((string)$cfg['user'], $user);
    $okPass = !empty($cfg['hash']) && password_verify($pass, (string)$cfg['hash']);
    if (!($okUser && $okPass)) { usleep(700000); fail('Sai tài khoản hoặc mật khẩu.', 401); }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
}

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    out(['ok' => true]);
}

if ($action === 'save') {
    if (!$isAdmin) fail('Cần đăng nhập admin.', 401);
    $b = body();
    if (!hash_equals((string)$_SESSION['csrf'], (string)($b['csrf'] ?? ''))) fail('Phiên làm việc đã hết hạn. Vui lòng đăng nhập lại.', 403);
    $in = $b['data'] ?? null;
    if (!is_array($in) || !isset($in['tasks']) || !is_array($in['tasks'])) fail('Dữ liệu không hợp lệ.');
    if (count($in['tasks']) > 100) fail('Quá nhiều đầu việc.');

    $tasks = [];
    foreach ($in['tasks'] as $t) {
        if (!is_array($t)) continue;
        $phase = (string)($t['phase'] ?? '');
        $status = (string)($t['status'] ?? 'Chưa bắt đầu');
        if (!in_array($phase, $PHASES, true)) fail('Giai đoạn không hợp lệ.');
        if (!in_array($status, $STATUSES, true)) fail('Trạng thái không hợp lệ.');
        if (!validDate($t['start'] ?? '') || !validDate($t['end'] ?? '')) fail('Ngày không hợp lệ.');
        $tasks[] = [
            'code' => clean($t['code'] ?? '', 12),
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
        ];
    }
    $kpi = [];
    foreach (($in['kpi'] ?? []) as $r) {
        if (!is_array($r)) continue;
        $kpi[] = [clean($r[0] ?? '', 200), clean($r[1] ?? '', 120), clean($r[2] ?? '', 120), clean($r[3] ?? '', 400)];
        if (count($kpi) >= 60) break;
    }
    $open = [];
    foreach (($in['open'] ?? []) as $o) {
        $s = clean($o, 400);
        if ($s !== '') $open[] = $s;
        if (count($open) >= 60) break;
    }
    // ghi nhận thời điểm đổi trạng thái và nhật ký
    $prev = loadData($storeFile, $seedFile);
    $prevMap = [];
    foreach ($prev['tasks'] as $pt) $prevMap[$pt['code']] = $pt;
    $now = date('c');
    $log = $prev['log'];
    foreach ($tasks as &$t) {
        $old = $prevMap[$t['code']] ?? null;
        if ($old === null || $old['status'] !== $t['status']) {
            $t['statusAt'] = $now;
            if ($old !== null) {
                array_unshift($log, ['at' => $now, 'code' => $t['code'], 'task' => mb_substr($t['task'], 0, 80, 'UTF-8'), 'from' => $old['status'], 'to' => $t['status']]);
            }
        } else {
            $t['statusAt'] = $old['statusAt'] ?? $now;
        }
    }
    unset($t);
    $log = array_slice($log, 0, 200);
    $store = ['tasks' => $tasks, 'kpi' => $kpi, 'open' => $open, 'log' => $log, 'updatedAt' => $now];
    if (!is_dir($storeDir)) mkdir($storeDir, 0755, true);
    $tmp = $storeFile . '.tmp';
    if (file_put_contents($tmp, json_encode($store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false || !rename($tmp, $storeFile)) {
        fail('Không lưu được. Kiểm tra quyền ghi thư mục noi-bo/data.', 500);
    }
    out(['ok' => true, 'data' => $store]);
}

fail('Yêu cầu không hợp lệ.', 404);
