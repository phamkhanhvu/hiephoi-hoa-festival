<?php
// Đăng ký dự Gala "Vũ Điệu Sắc Hoa" 18/12/2026: công khai gửi đăng ký; admin xem và xuất CSV.
declare(strict_types=1);
$base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
session_name('ttnb_sess');
session_set_cookie_params(['lifetime' => 0, 'path' => $base, 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MAX_ROWS = 3000;
const MAX_SEATS = 20;
const CLOSE_AT = '2026-12-10 23:59:59';
const EARLY_END = '2026-12-05 23:59:59'; // hết giá early bird
const PRICE_ADULT_EARLY = 2000000;
const PRICE_ADULT = 2500000;
const PRICE_KID = 1000000;
$dataDir = __DIR__ . '/data';
$file = $dataDir . '/registrations.json';
$rateFile = $dataDir . '/rate.json';
$KINDS = ['Nhóm đồng hành / tài trợ (01–08)', 'Khách dự riêng Gala (09)', 'Khách mời của Ban Tổ chức'];

function out(array $a, int $c = 200): void { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $c = 400): void { out(['ok' => false, 'error' => $m], $c); }
function clean($v, int $max): string { $s = is_scalar($v) ? trim((string)$v) : ''; $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s); return mb_substr((string)$s, 0, $max, 'UTF-8'); }
function readRows(string $f): array { $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; return is_array($d) ? $d : []; }
function csvCell(string $s): string { if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s; return '"' . str_replace('"', '""', $s) . '"'; }

if (!is_dir($dataDir)) @mkdir($dataDir, 0750, true);
if (!is_file($dataDir . '/.htaccess')) @file_put_contents($dataDir . '/.htaccess', "Require all denied\n");
$action = $_GET['action'] ?? 'register';
$isAdmin = !empty($_SESSION['admin']);

if ($action === 'status') out(['ok' => true, 'count' => count(readRows($file)), 'open' => time() <= strtotime(CLOSE_AT), 'early' => time() <= strtotime(EARLY_END)]);

if ($action === 'list' || $action === 'export') {
    if (!$isAdmin) fail('Cần đăng nhập ở trang truyền thông nội bộ trước.', 401);
    $rows = readRows($file);
    if ($action === 'list') out(['ok' => true, 'rows' => $rows]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dang-ky-gala-' . date('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF" . implode(',', ['STT', 'Thời gian', 'Hình thức', 'Đơn vị / họ tên', 'Người liên hệ', 'Điện thoại', 'Email', 'Người lớn', 'Trẻ em', 'Danh sách khách', 'Ghi chú', 'Mức giá', 'Tạm tính (VNĐ)']) . "\r\n";
    foreach ($rows as $r) echo implode(',', array_map('csvCell', [(string)$r['stt'], $r['at'], $r['kind'], $r['org'], $r['contact'], $r['phone'], $r['email'], (string)$r['adults'], (string)$r['kids'], $r['guests'], $r['note'], (string)($r['tier'] ?? ''), (string)($r['amount'] ?? 0)])) . "\r\n";
    exit;
}

if ($action !== 'register' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('Yêu cầu không hợp lệ.', 405);
if (time() > strtotime(CLOSE_AT)) fail('Đã hết hạn đăng ký Gala (10/12/2026).', 403);

$b = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($b)) fail('Dữ liệu không hợp lệ.');
if (!empty($b['website'])) out(['ok' => true, 'stt' => 0, 'code' => '0000']);

$kind = clean($b['kind'] ?? '', 80);
$org = clean($b['org'] ?? '', 120);
$contact = clean($b['contact'] ?? '', 80);
$phone = preg_replace('/[^0-9+]/', '', clean($b['phone'] ?? '', 20)) ?? '';
$email = clean($b['email'] ?? '', 120);
$adults = (int)($b['adults'] ?? 0);
$kids = (int)($b['kids'] ?? 0);
$guests = clean($b['guests'] ?? '', 400);
$note = clean($b['note'] ?? '', 300);
if (!in_array($kind, $KINDS, true)) fail('Vui lòng chọn hình thức tham dự.');
if ($org === '') fail('Vui lòng nhập tên đơn vị hoặc họ tên.');
if ($contact === '') fail('Vui lòng nhập tên người liên hệ.');
if (!preg_match('/^(\+?84|0)[0-9]{8,10}$/', $phone)) fail('Số điện thoại chưa đúng.');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Email chưa đúng.');
if ($adults < 1 || $adults > MAX_SEATS || $kids < 0 || $kids > MAX_SEATS) fail('Số khách chưa đúng (tối đa ' . MAX_SEATS . ' mỗi loại, ít nhất 1 người lớn).');
if (empty($b['agree'])) fail('Vui lòng xác nhận thông tin đã khai.');

$ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'gala');
$fh = fopen($dataDir . '/.lock', 'c');
if (!$fh || !flock($fh, LOCK_EX)) fail('Máy chủ bận, thử lại sau.', 503);
$rate = readRows($rateFile);
$now = time();
foreach ($rate as $k => $ts) { $rate[$k] = array_values(array_filter($ts, fn($t) => $now - $t < 3600)); if (!$rate[$k]) unset($rate[$k]); }
if (count($rate[$ip] ?? []) >= 10) { flock($fh, LOCK_UN); fail('Bạn gửi quá nhiều lần, vui lòng thử lại sau.', 429); }
$rows = readRows($file);
if (count($rows) >= MAX_ROWS) { flock($fh, LOCK_UN); fail('Đã đủ số lượng đăng ký.', 403); }
$stt = $rows ? max(array_column($rows, 'stt')) + 1 : 1;
$paying = $kind === $KINDS[1];
$early = $now <= strtotime(EARLY_END);
$tier = $paying ? ($early ? 'Early bird' : 'Giá thường') : 'Không thu phí';
$amount = $paying ? $adults * ($early ? PRICE_ADULT_EARLY : PRICE_ADULT) + $kids * PRICE_KID : 0;
$rows[] = ['stt' => $stt, 'at' => date('Y-m-d H:i:s'), 'kind' => $kind, 'org' => $org, 'contact' => $contact, 'phone' => $phone, 'email' => $email, 'adults' => $adults, 'kids' => $kids, 'guests' => $guests, 'note' => $note, 'tier' => $tier, 'amount' => $amount];
$rate[$ip][] = $now;
$ok = file_put_contents($file . '.tmp', json_encode($rows, JSON_UNESCAPED_UNICODE)) !== false && rename($file . '.tmp', $file);
file_put_contents($rateFile, json_encode($rate));
flock($fh, LOCK_UN);
if (!$ok) fail('Không lưu được, vui lòng thử lại.', 500);
out(['ok' => true, 'stt' => $stt, 'code' => str_pad((string)$stt, 4, '0', STR_PAD_LEFT), 'tier' => $tier, 'amount' => $amount]);
