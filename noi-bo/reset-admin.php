<?php
// Đặt lại tài khoản admin 1 lần. Cần mã trong đường dẫn (?k=...). Tạo xong file tự xóa.
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
$TOKEN = '1c86605ccba7b9094eba76f2';
if (!hash_equals($TOKEN, (string)($_GET['k'] ?? ''))) { http_response_code(404); exit; }
$cfgFile = __DIR__ . '/config.local.php';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['user'] ?? ''));
    $p1 = (string)($_POST['pass'] ?? '');
    $p2 = (string)($_POST['pass2'] ?? '');
    if ($user === '' || mb_strlen($p1) < 10) $msg = 'Tên đăng nhập không được trống và mật khẩu tối thiểu 10 ký tự.';
    elseif ($p1 !== $p2) $msg = 'Hai lần nhập mật khẩu chưa khớp.';
    else {
        $hash = password_hash($p1, PASSWORD_DEFAULT);
        $code = "<?php\n// Tạo tự động. Không đưa file này lên git.\nreturn [\n    'user' => " . var_export($user, true) . ",\n    'hash' => " . var_export($hash, true) . ",\n];\n";
        if (file_put_contents($cfgFile, $code) === false) { $msg = 'Không ghi được file cấu hình. Kiểm tra quyền thư mục.'; }
        else { @unlink(__FILE__); echo '<p>Đã đặt lại tài khoản admin. Quay lại trang truyền thông và đăng nhập.</p>'; exit; }
    }
}
?>
<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Đặt lại admin</title>
<style>body{font:15px system-ui,sans-serif;max-width:420px;margin:40px auto;padding:0 16px}input{width:100%;padding:8px;margin:4px 0 12px;box-sizing:border-box;font-size:16px}button{padding:10px 16px}.err{color:#b3261e}</style></head>
<body><h2>Đặt lại tài khoản admin</h2>
<?php if ($msg): ?><p class="err"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post" autocomplete="off">
<label>Tên đăng nhập<input name="user" required></label>
<label>Mật khẩu mới (tối thiểu 10 ký tự)<input name="pass" type="password" required minlength="10"></label>
<label>Nhập lại mật khẩu<input name="pass2" type="password" required minlength="10"></label>
<button type="submit">Đặt lại</button>
</form></body></html>
