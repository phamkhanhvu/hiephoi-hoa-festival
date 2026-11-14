<?php
// Chạy 1 lần để tạo tài khoản admin. Khi tạo xong, file này tự xóa.
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
$cfgFile = __DIR__ . '/config.local.php';
if (is_file($cfgFile)) { $self = __FILE__; echo '<p>Đã có tài khoản admin. Không thể tạo lại.</p>'; exit; }
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
        else {
            @unlink(__FILE__);
            echo '<p>Đã tạo tài khoản admin. Quay lại trang truyền thông và đăng nhập.</p>';
            exit;
        }
    }
}
?>
<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Tạo tài khoản admin</title>
<style>body{font:15px system-ui,sans-serif;max-width:420px;margin:40px auto;padding:0 16px}input{width:100%;padding:8px;margin:4px 0 12px;box-sizing:border-box}button{padding:10px 16px}.err{color:#b3261e}</style></head>
<body><h2>Tạo tài khoản admin</h2>
<?php if ($msg): ?><p class="err"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post" autocomplete="off">
<label>Tên đăng nhập<input name="user" required></label>
<label>Mật khẩu (tối thiểu 10 ký tự)<input name="pass" type="password" required minlength="10"></label>
<label>Nhập lại mật khẩu<input name="pass2" type="password" required minlength="10"></label>
<button type="submit">Tạo tài khoản</button>
</form></body></html>
