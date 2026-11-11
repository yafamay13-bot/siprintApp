<?php

declare(strict_types=1);

define('SIPRINT_ROOT', dirname(__DIR__, 2));
define('SIPRINT_STORAGE', SIPRINT_ROOT . DIRECTORY_SEPARATOR . 'storage');
define('MAX_DOCUMENT_SIZE', 15 * 1024 * 1024);
define('MAX_PROFILE_PHOTO_SIZE', 2 * 1024 * 1024);

date_default_timezone_set('Asia/Jakarta');

$configFile = SIPRINT_ROOT . DIRECTORY_SEPARATOR . 'config.php';
if (is_file($configFile)) {
    $config = require $configFile;
} else {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>SIPRINT belum dikonfigurasi</title><body><h1>SIPRINT belum dikonfigurasi</h1><p>Salin <code>config.example.php</code> menjadi <code>config.php</code>, lalu isi koneksi MySQL dan rahasia sesi.</p></body></html>';
    exit;
}

if (!is_array($config) || strlen((string)($config['session_secret'] ?? '')) < 32
    || str_contains((string)($config['session_secret'] ?? ''), 'replace-with-a-random-secret')) {
    http_response_code(503);
    exit('Konfigurasi SIPRINT tidak valid. session_secret wajib minimal 32 karakter.');
}

define('SIPRINT_UPLOADS', rtrim((string)($config['upload_dir'] ?? SIPRINT_STORAGE), '\\/') . DIRECTORY_SEPARATOR . 'uploads');
define('SIPRINT_PROFILE_PHOTOS', rtrim((string)($config['upload_dir'] ?? SIPRINT_STORAGE), '\\/') . DIRECTORY_SEPARATOR . 'profile-photos');

$scriptName = rawurldecode(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
$basePath = rawurldecode(rtrim(str_replace('\\', '/', dirname($scriptName)), '/.'));
if ($basePath === '/') {
    $basePath = '';
}
define('SIPRINT_BASE_PATH', $basePath);

function url(string $path): string
{
    $base = implode('/', array_map('rawurlencode', array_filter(explode('/', trim(SIPRINT_BASE_PATH, '/')), static fn(string $part): bool => $part !== '')));
    return ($base !== '' ? '/' . $base : '') . '/' . ltrim($path, '/');
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO
{
    static $pdo;
    global $config;
    if (!$pdo) {
        $host = (string)($config['db_host'] ?? '127.0.0.1');
        $port = (int)($config['db_port'] ?? 3306);
        $name = (string)($config['db_name'] ?? 'siprint');
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            (string)($config['db_user'] ?? ''),
            (string)($config['db_password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function query(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        global $config;
        $_SESSION['csrf_token'] = hash_hmac('sha256', random_bytes(32) . session_id(), (string)$config['session_secret']);
    }
    return (string)$_SESSION['csrf_token'];
}

function csrf_valid(mixed $submitted): bool
{
    return is_string($submitted) && isset($_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], $submitted);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][$type][] = $message;
}

function take_flash(): array
{
    $messages = $_SESSION['flash'] ?? ['success' => [], 'error' => []];
    unset($_SESSION['flash']);
    return [
        'success' => array_slice($messages['success'] ?? [], 0, 1),
        'error' => array_slice($messages['error'] ?? [], 0, 1),
    ];
}

function redirect(string $path): never
{
    header('Location: ' . url($path), true, 303);
    exit;
}

function render(string $view, array $data = [], int $status = 200): never
{
    http_response_code($status);
    $user = $_SESSION['user'] ?? null;
    $notifications = [];
    $unreadCount = 0;
    if ($user) {
        try {
            $unreadCount = (int)(query(
                'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE',
                [$user['id']]
            )->fetchColumn() ?: 0);
            $notifications = query(
                'SELECT id, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5',
                [$user['id']]
            )->fetchAll();
        } catch (Throwable) {
            $unreadCount = 0;
            $notifications = [];
        }
    }

    $context = array_merge([
        'currentUser' => $user,
        'csrfToken' => csrf_token(),
        'flashMessages' => take_flash(),
        'unreadNotificationCount' => $unreadCount,
        'recentNotifications' => $notifications,
        'greeting' => greeting(),
        'title' => 'SIPRINT',
        'message' => '',
        'bodyClass' => 'app-page',
    ], $data);
    $GLOBALS['siprintContext'] = $context;
    extract($context, EXTR_SKIP);
    require SIPRINT_ROOT . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . $view . '.php';
    exit;
}

function format_date(string|null $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Jakarta'))
            ->format('d M Y, H:i');
    } catch (Throwable) {
        return e($value);
    }
}

function greeting(): string
{
    $hour = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('G');
    return $hour < 11 ? 'pagi' : ($hour < 15 ? 'siang' : ($hour < 19 ? 'sore' : 'malam'));
}

function audit(int|string|null $userId, string $action, string $entityType, int|string|null $entityId = null, array $details = []): void
{
    $encodedDetails = $details === []
        ? '{}'
        : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    query(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
        [$userId ?: null, $action, $entityType, $entityId, $encodedDetails, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]
    );
}

function notify(int|string $userId, string $title, string $message, string $link): void
{
    query('INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)', [
        $userId, $title, mb_substr($message, 0, 500, 'UTF-8'), $link,
    ]);
}

function safe_internal_link(mixed $link): bool
{
    return is_string($link) && str_starts_with($link, '/') && !str_starts_with($link, '//')
        && !str_contains($link, '\\') && !preg_match('/[\r\n]/', $link);
}

function valid_password(string $password, int $minimum = 12): bool
{
    return mb_strlen($password, 'UTF-8') >= $minimum && strlen($password) <= 72;
}

function is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function require_auth(): array
{
    if (empty($_SESSION['user']['id'])) {
        flash('error', 'Silakan masuk terlebih dahulu.');
        redirect('/login');
    }
    $user = query(
        'SELECT is_active, auth_version, profile_photo FROM users WHERE id = ? LIMIT 1',
        [$_SESSION['user']['id']]
    )->fetch();
    if (!$user || !(int)$user['is_active'] || (int)$user['auth_version'] !== (int)($_SESSION['user']['authVersion'] ?? -1)) {
        $_SESSION = [];
        session_destroy();
        clear_session_cookie();
        redirect('/login');
    }
    $_SESSION['user']['profilePhoto'] = $user['profile_photo'];
    return $_SESSION['user'];
}

function clear_session_cookie(): void
{
    if (!ini_get('session.use_cookies')) {
        return;
    }
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Strict',
    ]);
}

function require_role(string $role): array
{
    $user = require_auth();
    if ($user['role'] !== $role) {
        render('error', ['title' => 'Akses ditolak', 'message' => 'Anda tidak memiliki hak akses untuk membuka halaman ini.'], 403);
    }
    return $user;
}

function can_access_document(?array $user, ?array $document): bool
{
    return $user && $document && ($user['role'] === 'admin'
        || ($user['role'] === 'pegawai' && (string)$user['id'] === (string)$document['user_id']));
}

function safe_stored_filename(mixed $name): bool
{
    return is_string($name) && basename($name) === $name
        && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(?:pdf|jpg|png|webp)\z/i', $name) === 1;
}

function send_private_file(string $path, string $contentType, ?string $downloadName = null): never
{
    if (!is_file($path) || !is_readable($path)) {
        render('error', ['title' => 'File tidak ditemukan', 'message' => 'File tidak tersedia di penyimpanan.'], 404);
    }
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . (string)filesize($path));
    if ($downloadName !== null) {
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'document.pdf';
        header('Content-Disposition: attachment; filename="' . addcslashes($fallback, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    } else {
        header('Content-Disposition: inline');
    }
    readfile($path);
    exit;
}

function login_rate_limited(bool $increment = false): bool
{
    $directory = SIPRINT_STORAGE . DIRECTORY_SEPARATOR . 'rate-limit';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        return true;
    }
    $key = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $file = $directory . DIRECTORY_SEPARATOR . $key . '.json';
    $handle = fopen($file, 'c+');
    if (!$handle) {
        return true;
    }
    try {
        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $state = json_decode($raw ?: '', true);
        $now = time();
        if (!is_array($state) || ($now - (int)($state['start'] ?? 0)) >= 900) {
            $state = ['start' => $now, 'count' => 0];
        }
        $limited = (int)$state['count'] >= 10;
        if ($increment && !$limited) {
            $state['count']++;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state));
        }
        return $limited;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function request_path(): string
{
    $path = rawurldecode((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
    if (SIPRINT_BASE_PATH !== '' && ($path === SIPRINT_BASE_PATH || str_starts_with($path, SIPRINT_BASE_PATH . '/'))) {
        $path = substr($path, strlen(SIPRINT_BASE_PATH));
    }
    $path = '/' . trim($path, '/');
    return $path === '/index.php' ? '/' : $path;
}

function uploaded_file_error(int $error): ?string
{
    return match ($error) {
        UPLOAD_ERR_OK => null,
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'SIZE',
        UPLOAD_ERR_NO_FILE => 'NO_FILE',
        default => 'UPLOAD',
    };
}

function random_filename(string $extension): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20) . $extension;
}

function app(): void
{
    global $config;

    $secure = (bool)($config['secure_cookies'] ?? false)
        || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; img-src 'self' data: blob:; object-src 'none'; frame-ancestors 'self';");

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '28800');
    session_name('siprint_sid');
    session_set_cookie_params([
        'lifetime' => 28800,
        'path' => url('/'),
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = request_path();
    if ($method === 'POST' && !csrf_valid($_POST['_csrf'] ?? null)) {
        render('error', [
            'title' => 'Permintaan tidak valid',
            'message' => 'Sesi formulir tidak valid atau telah kedaluwarsa. Muat ulang halaman, lalu coba lagi.',
        ], 403);
    }

    try {
        if ($path === '/login' && $method === 'GET') {
            if (!empty($_SESSION['user'])) {
                redirect('/');
            }
            render('login', ['title' => 'Masuk', 'bodyClass' => 'login-page']);
        }

        if ($path === '/login' && $method === 'POST') {
            if (login_rate_limited()) {
                flash('error', 'Terlalu banyak percobaan masuk. Silakan coba kembali beberapa menit lagi.');
                redirect('/login');
            }
            login_rate_limited(true);
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            $user = query(
                'SELECT id, name, email, password_hash, role, is_active, auth_version, profile_photo FROM users WHERE email = ? LIMIT 1',
                [$email]
            )->fetch();
            static $dummyHash = null;
            $dummyHash ??= password_hash('siprint-placeholder-password', PASSWORD_BCRYPT, ['cost' => 12]);
            $passwordMatches = password_verify($password, $user['password_hash'] ?? $dummyHash);
            if (!$user || !$passwordMatches || !(int)$user['is_active']) {
                flash('error', 'Email atau kata sandi tidak sesuai.');
                redirect('/login');
            }
            session_regenerate_id(true);
            $_SESSION = [];
            $_SESSION['user'] = [
                'id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'],
                'role' => $user['role'], 'authVersion' => $user['auth_version'],
                'profilePhoto' => $user['profile_photo'],
            ];
            csrf_token();
            try {
                audit($user['id'], 'login', 'session');
            } catch (Throwable $exception) {
                $_SESSION = [];
                session_destroy();
                clear_session_cookie();
                throw $exception;
            }
            flash('success', 'Selamat datang, ' . $user['name'] . '.');
            redirect('/');
        }

        if ($path === '/logout' && $method === 'POST') {
            $user = require_auth();
            audit($user['id'], 'logout', 'session');
            $_SESSION = [];
            clear_session_cookie();
            session_destroy();
            redirect('/login');
        }

        if ($path === '/' && $method === 'GET') {
            $user = require_auth();
            $scope = $user['role'] === 'admin' ? '' : ' WHERE user_id = ?';
            $values = $user['role'] === 'admin' ? [] : [$user['id']];
            $counts = query(
                "SELECT COUNT(*) AS total, SUM(status = 'menunggu') AS menunggu, SUM(status = 'diproses') AS diproses, SUM(status = 'selesai') AS selesai FROM documents{$scope}",
                $values
            )->fetch() ?: [];
            if ($user['role'] === 'admin') {
                $recent = query('SELECT d.id, d.title, d.copies, d.status, d.created_at, u.name AS employee_name FROM documents d JOIN users u ON u.id = d.user_id ORDER BY d.created_at DESC LIMIT 6')->fetchAll();
                $queueCount = (int)query("SELECT COUNT(*) FROM documents WHERE status = 'menunggu'")->fetchColumn();
            } else {
                $recent = query('SELECT id, title, copies, status, created_at FROM documents WHERE user_id = ? ORDER BY created_at DESC LIMIT 6', [$user['id']])->fetchAll();
                $queueCount = 0;
            }
            render('dashboard', ['title' => 'Dashboard', 'counts' => $counts, 'recentDocuments' => $recent, 'queueCount' => $queueCount]);
        }

        if ($path === '/profil' && $method === 'GET') {
            require_auth();
            render('profile', ['title' => 'Profil Saya']);
        }

        if (preg_match('#\A/foto-profil/([0-9]+)\z#', $path, $match) && $method === 'GET') {
            $user = require_auth();
            if ($user['role'] !== 'admin' && (string)$user['id'] !== $match[1]) {
                render('error', ['title' => 'Tidak ditemukan', 'message' => 'Foto tidak ditemukan.'], 404);
            }
            $photo = query('SELECT profile_photo FROM users WHERE id = ? LIMIT 1', [$match[1]])->fetchColumn();
            if (!safe_stored_filename($photo) || !preg_match('/\.(jpg|png|webp)\z/i', $photo)) {
                render('error', ['title' => 'Tidak ditemukan', 'message' => 'Foto tidak ditemukan.'], 404);
            }
            $type = str_ends_with(strtolower($photo), '.png') ? 'image/png' : (str_ends_with(strtolower($photo), '.webp') ? 'image/webp' : 'image/jpeg');
            send_private_file(SIPRINT_PROFILE_PHOTOS . DIRECTORY_SEPARATOR . $photo, $type);
        }

        if ($path === '/profil/foto' && $method === 'POST') {
            $user = require_auth();
            $error = uploaded_file_error((int)($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE));
            if ($error !== null) {
                flash('error', $error === 'SIZE' ? 'Ukuran foto maksimal 2 MB.' : 'Pilih foto profil terlebih dahulu.');
                redirect('/profil');
            }
            $file = $_FILES['profile_photo'];
            $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
            $allowed = ['jpg' => ['image/jpeg', IMAGETYPE_JPEG], 'jpeg' => ['image/jpeg', IMAGETYPE_JPEG], 'png' => ['image/png', IMAGETYPE_PNG], 'webp' => ['image/webp', IMAGETYPE_WEBP]];
            $imageInfo = @getimagesize((string)$file['tmp_name']);
            if ((int)$file['size'] > MAX_PROFILE_PHOTO_SIZE) {
                flash('error', 'Ukuran foto maksimal 2 MB.');
                redirect('/profil');
            }
            if (!isset($allowed[$extension]) || $mime !== $allowed[$extension][0] || !$imageInfo || $imageInfo[2] !== $allowed[$extension][1]) {
                flash('error', 'Isi file tidak valid sebagai foto JPG, PNG, atau WebP.');
                redirect('/profil');
            }
            $extension = $extension === 'jpeg' ? 'jpg' : $extension;
            if (!is_dir(SIPRINT_PROFILE_PHOTOS)) {
                mkdir(SIPRINT_PROFILE_PHOTOS, 0700, true);
            }
            $filename = random_filename('.' . $extension);
            $destination = SIPRINT_PROFILE_PHOTOS . DIRECTORY_SEPARATOR . $filename;
            if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
                flash('error', 'Foto tidak dapat diunggah. Silakan coba kembali.');
                redirect('/profil');
            }
            try {
                db()->beginTransaction();
                $previous = query('SELECT profile_photo FROM users WHERE id = ? AND is_active = TRUE FOR UPDATE', [$user['id']])->fetchColumn();
                if ($previous === false) {
                    db()->rollBack();
                    @unlink($destination);
                    flash('error', 'Akun tidak aktif atau tidak ditemukan.');
                    redirect('/login');
                }
                query('UPDATE users SET profile_photo = ? WHERE id = ?', [$filename, $user['id']]);
                audit($user['id'], 'profile_photo_updated', 'user', $user['id']);
                db()->commit();
                $_SESSION['user']['profilePhoto'] = $filename;
                if (safe_stored_filename($previous)) {
                    @unlink(SIPRINT_PROFILE_PHOTOS . DIRECTORY_SEPARATOR . $previous);
                }
                flash('success', 'Foto profil berhasil diperbarui.');
                redirect('/profil');
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                @unlink($destination);
                throw $exception;
            }
        }

        if ($path === '/profil' && $method === 'POST') {
            $user = require_auth();
            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            $changingPassword = $newPassword !== '';
            if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 120 || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'Isi nama lengkap dan alamat email yang valid.');
                redirect('/profil');
            }
            if (($changingPassword && (!valid_password($newPassword) || $newPassword !== $confirmPassword))
                || (!$changingPassword && $confirmPassword !== '')) {
                flash('error', 'Kata sandi baru harus minimal 12 karakter (maksimal 72 byte) dan konfirmasinya harus cocok.');
                redirect('/profil');
            }
            try {
                db()->beginTransaction();
                $account = query('SELECT id, password_hash, auth_version FROM users WHERE id = ? AND is_active = TRUE FOR UPDATE', [$user['id']])->fetch();
                if (!$account) {
                    db()->rollBack();
                    flash('error', 'Akun tidak aktif atau tidak ditemukan.');
                    redirect('/login');
                }
                $hash = $account['password_hash'];
                if ($changingPassword) {
                    if ($currentPassword === '' || !password_verify($currentPassword, $hash)) {
                        db()->rollBack();
                        flash('error', 'Kata sandi saat ini tidak sesuai.');
                        redirect('/profil');
                    }
                    $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                    query('UPDATE users SET name = ?, email = ?, password_hash = ?, auth_version = auth_version + 1 WHERE id = ?', [$name, $email, $hash, $user['id']]);
                } else {
                    query('UPDATE users SET name = ?, email = ? WHERE id = ?', [$name, $email, $user['id']]);
                }
                audit($user['id'], $changingPassword ? 'profile_and_password_updated' : 'profile_updated', 'user', $user['id'], ['email' => $email, 'passwordChanged' => $changingPassword]);
                db()->commit();
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['email'] = $email;
                if ($changingPassword) {
                    $_SESSION['user']['authVersion'] = (int)$account['auth_version'] + 1;
                }
                flash('success', $changingPassword ? 'Profil dan kata sandi berhasil diperbarui.' : 'Profil berhasil diperbarui.');
                redirect('/profil');
            } catch (PDOException $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                if ($exception->getCode() === '23000') {
                    flash('error', 'Email tersebut sudah digunakan oleh akun lain.');
                    redirect('/profil');
                }
                throw $exception;
            }
        }

        if ($path === '/notifikasi' && $method === 'GET') {
            $user = require_auth();
            $notifications = query('SELECT id, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100', [$user['id']])->fetchAll();
            render('notifications', ['title' => 'Notifikasi', 'notifications' => $notifications]);
        }

        if ($path === '/notifikasi/baca-semua' && $method === 'POST') {
            $user = require_auth();
            query('UPDATE notifications SET is_read = TRUE, read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND is_read = FALSE', [$user['id']]);
            flash('success', 'Semua notifikasi ditandai sudah dibaca.');
            redirect('/notifikasi');
        }

        if (preg_match('#\A/notifikasi/([0-9]+)/baca\z#', $path, $match) && $method === 'POST') {
            $user = require_auth();
            $notification = query('SELECT link FROM notifications WHERE id = ? AND user_id = ? LIMIT 1', [$match[1], $user['id']])->fetch();
            if (!$notification) {
                render('error', ['title' => 'Notifikasi tidak ditemukan', 'message' => 'Notifikasi tidak ditemukan atau bukan milik akun Anda.'], 404);
            }
            query('UPDATE notifications SET is_read = TRUE, read_at = COALESCE(read_at, CURRENT_TIMESTAMP) WHERE id = ? AND user_id = ?', [$match[1], $user['id']]);
            redirect(safe_internal_link($notification['link']) ? $notification['link'] : '/notifikasi');
        }

        if ($path === '/dokumen' && $method === 'GET') {
            $user = require_auth();
            if ($user['role'] === 'admin') {
                $documents = query('SELECT d.*, u.name AS employee_name FROM documents d JOIN users u ON u.id = d.user_id ORDER BY d.created_at DESC LIMIT 200')->fetchAll();
            } else {
                $documents = query('SELECT * FROM documents WHERE user_id = ? ORDER BY created_at DESC LIMIT 200', [$user['id']])->fetchAll();
            }
            render('documents', ['title' => $user['role'] === 'admin' ? 'Data Dokumen' : 'Dokumen Saya', 'documents' => $documents]);
        }

        if ($path === '/dokumen/baru' && $method === 'GET') {
            require_role('pegawai');
            render('upload', ['title' => 'Kirim Dokumen']);
        }

        if ($path === '/dokumen' && $method === 'POST') {
            $user = require_role('pegawai');
            $error = uploaded_file_error((int)($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE));
            if ($error !== null) {
                flash('error', $error === 'SIZE' ? 'Ukuran file maksimal 15 MB.' : 'Pilih file PDF untuk dikirim.');
                redirect('/dokumen/baru');
            }
            $file = $_FILES['document'];
            $title = trim((string)($_POST['title'] ?? ''));
            $copies = filter_var($_POST['copies'] ?? null, FILTER_VALIDATE_INT);
            $notes = trim((string)($_POST['notes'] ?? ''));
            $originalName = basename(str_replace('\\', '/', (string)$file['name']));
            $isPdf = strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) === 'pdf'
                && (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']) === 'application/pdf'
                && file_get_contents((string)$file['tmp_name'], false, null, 0, 5) === '%PDF-';
            if (!$isPdf) {
                flash('error', 'Isi file tidak valid sebagai dokumen PDF.');
                redirect('/dokumen/baru');
            }
            if ((int)$file['size'] > MAX_DOCUMENT_SIZE || mb_strlen($title, 'UTF-8') < 2 || mb_strlen($title, 'UTF-8') > 160
                || $copies === false || $copies < 1 || $copies > 500 || mb_strlen($notes, 'UTF-8') > 1000) {
                flash('error', 'Periksa nama dokumen, jumlah salinan (1–500), ukuran file maksimal 15 MB, dan keterangan.');
                redirect('/dokumen/baru');
            }
            if (!is_dir(SIPRINT_UPLOADS)) {
                mkdir(SIPRINT_UPLOADS, 0700, true);
            }
            $filename = random_filename('.pdf');
            $destination = SIPRINT_UPLOADS . DIRECTORY_SEPARATOR . $filename;
            if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
                flash('error', 'File tidak dapat disimpan. Periksa izin folder penyimpanan.');
                redirect('/dokumen/baru');
            }
            try {
                db()->beginTransaction();
                $boundedName = function_exists('mb_strcut') ? mb_strcut($originalName, 0, 255, 'UTF-8') : substr($originalName, 0, 255);
                query('INSERT INTO documents (user_id, title, copies, notes, original_filename, stored_filename, file_size) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                    $user['id'], $title, $copies, $notes !== '' ? $notes : null, $boundedName, $filename, (int)$file['size'],
                ]);
                $documentId = (int)db()->lastInsertId();
                audit($user['id'], 'document_submitted', 'document', $documentId, ['title' => $title, 'copies' => $copies]);
                $admins = query("SELECT id FROM users WHERE role = 'admin' AND is_active = TRUE")->fetchAll();
                foreach ($admins as $admin) {
                    notify($admin['id'], 'Dokumen baru masuk', $user['name'] . ' mengirim dokumen “' . $title . '”.', '/admin/antrean');
                }
                db()->commit();
                flash('success', 'Dokumen berhasil dikirim dan masuk ke antrean admin.');
                redirect('/dokumen');
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                @unlink($destination);
                throw $exception;
            }
        }

        if (preg_match('#\A/dokumen/([0-9]+)\z#', $path, $match) && $method === 'GET') {
            $user = require_auth();
            $document = query('SELECT id, user_id, title, copies, notes, original_filename, stored_filename, status, created_at FROM documents WHERE id = ? LIMIT 1', [$match[1]])->fetch();
            if (!can_access_document($user, $document)) {
                render('error', ['title' => 'Dokumen tidak ditemukan', 'message' => 'Dokumen tidak ditemukan atau Anda tidak memiliki izin untuk membukanya.'], 404);
            }
            $backToQueue = $user['role'] === 'admin' && in_array($document['status'], ['menunggu', 'diproses'], true);
            render('document-preview', [
                'title' => 'Pratinjau Dokumen', 'document' => $document,
                'backUrl' => $backToQueue ? '/admin/antrean' : '/dokumen',
                'backLabel' => $backToQueue ? 'Kembali ke antrean' : 'Kembali ke dokumen',
                'autoPrint' => ($_GET['print'] ?? '') === '1',
            ]);
        }

        if (preg_match('#\A/dokumen/([0-9]+)/(preview-file|file)\z#', $path, $match) && $method === 'GET') {
            $user = require_auth();
            $document = query('SELECT id, user_id, original_filename, stored_filename FROM documents WHERE id = ? LIMIT 1', [$match[1]])->fetch();
            if (!can_access_document($user, $document) || !safe_stored_filename($document['stored_filename'] ?? null) || !str_ends_with(strtolower($document['stored_filename']), '.pdf')) {
                render('error', ['title' => 'Dokumen tidak ditemukan', 'message' => 'Dokumen tidak ditemukan atau Anda tidak memiliki izin untuk membukanya.'], 404);
            }
            send_private_file(SIPRINT_UPLOADS . DIRECTORY_SEPARATOR . $document['stored_filename'], 'application/pdf', $match[2] === 'file' ? $document['original_filename'] : null);
        }

        if (preg_match('#\A/admin/dokumen/([0-9]+)/hapus\z#', $path, $match) && $method === 'POST') {
            $user = require_role('admin');
            db()->beginTransaction();
            try {
                $document = query('SELECT id, title, stored_filename FROM documents WHERE id = ? FOR UPDATE', [$match[1]])->fetch();
                if (!$document) {
                    db()->rollBack();
                    flash('error', 'Dokumen tidak ditemukan.');
                    redirect('/dokumen');
                }
                query('DELETE FROM documents WHERE id = ?', [$document['id']]);
                audit($user['id'], 'document_deleted', 'document', $document['id'], ['title' => $document['title']]);
                db()->commit();
                if (safe_stored_filename($document['stored_filename'])) {
                    @unlink(SIPRINT_UPLOADS . DIRECTORY_SEPARATOR . $document['stored_filename']);
                }
                flash('success', 'Dokumen dan file terkait berhasil dihapus.');
                redirect('/dokumen');
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
        }

        if ($path === '/admin/antrean' && $method === 'GET') {
            require_role('admin');
            $documents = query("SELECT d.*, u.name AS employee_name, u.email AS employee_email FROM documents d JOIN users u ON u.id = d.user_id WHERE d.status IN ('menunggu', 'diproses') ORDER BY CASE d.status WHEN 'menunggu' THEN 0 ELSE 1 END, d.created_at ASC LIMIT 200")->fetchAll();
            render('queue', ['title' => 'Antrean Cetak', 'documents' => $documents]);
        }

        if (preg_match('#\A/admin/dokumen/([0-9]+)/status\z#', $path, $match) && $method === 'POST') {
            $user = require_role('admin');
            $status = (string)($_POST['status'] ?? '');
            $reason = trim((string)($_POST['rejection_reason'] ?? ''));
            if (!in_array($status, ['menunggu', 'diproses', 'selesai', 'ditolak'], true)
                || ($status === 'ditolak' && (mb_strlen($reason, 'UTF-8') < 3 || mb_strlen($reason, 'UTF-8') > 500))) {
                flash('error', 'Status atau alasan penolakan tidak valid.');
                redirect('/admin/antrean');
            }
            db()->beginTransaction();
            try {
                $document = query('SELECT id, user_id, title, status FROM documents WHERE id = ? FOR UPDATE', [$match[1]])->fetch();
                $transitions = ['menunggu' => ['diproses', 'ditolak'], 'diproses' => ['selesai', 'ditolak']];
                if (!$document || !in_array($status, $transitions[$document['status']] ?? [], true)) {
                    db()->rollBack();
                    flash('error', 'Perubahan status tidak tersedia untuk dokumen tersebut.');
                    redirect('/admin/antrean');
                }
                query('UPDATE documents SET status = ?, rejection_reason = ? WHERE id = ?', [$status, $status === 'ditolak' ? $reason : null, $document['id']]);
                audit($user['id'], 'document_status_changed', 'document', $document['id'], ['from' => $document['status'], 'to' => $status, 'reason' => $status === 'ditolak' ? $reason : null]);
                $labels = ['diproses' => 'sedang diproses', 'selesai' => 'selesai dicetak', 'ditolak' => 'ditolak'];
                $message = $status === 'ditolak' ? 'Dokumen “' . $document['title'] . '” ditolak. Alasan: ' . $reason : 'Dokumen “' . $document['title'] . '” ' . $labels[$status] . '.';
                notify($document['user_id'], 'Status dokumen diperbarui', $message, '/dokumen');
                db()->commit();
                flash('success', 'Status dokumen berhasil diperbarui.');
                redirect('/admin/antrean');
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
        }

        if ($path === '/admin/pegawai' && $method === 'GET') {
            require_role('admin');
            $employees = query("SELECT id, name, email, is_active, created_at FROM users WHERE role = 'pegawai' ORDER BY name ASC")->fetchAll();
            render('employees', ['title' => 'Data Pegawai', 'employees' => $employees]);
        }

        if ($path === '/admin/pegawai' && $method === 'POST') {
            $user = require_role('admin');
            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 120 || strlen($email) > 190
                || !filter_var($email, FILTER_VALIDATE_EMAIL) || !valid_password($password)) {
                flash('error', 'Isi nama, email valid, dan kata sandi minimal 12 karakter (maksimal 72 byte).');
                redirect('/admin/pegawai');
            }
            try {
                db()->beginTransaction();
                query('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)', [$name, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), 'pegawai']);
                $employeeId = (int)db()->lastInsertId();
                audit($user['id'], 'employee_created', 'user', $employeeId, ['email' => $email]);
                db()->commit();
                flash('success', 'Akun pegawai ' . $name . ' berhasil dibuat.');
                redirect('/admin/pegawai');
            } catch (PDOException $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                if ($exception->getCode() === '23000') {
                    flash('error', 'Email tersebut sudah digunakan.');
                    redirect('/admin/pegawai');
                }
                throw $exception;
            }
        }

        if (preg_match('#\A/admin/pegawai/([0-9]+)/aktif\z#', $path, $match) && $method === 'POST') {
            $user = require_role('admin');
            db()->beginTransaction();
            try {
                $result = query("UPDATE users SET is_active = NOT is_active, auth_version = auth_version + 1 WHERE id = ? AND role = 'pegawai'", [$match[1]]);
                if (!$result->rowCount()) {
                    db()->rollBack();
                    flash('error', 'Akun pegawai tidak ditemukan.');
                    redirect('/admin/pegawai');
                }
                $active = (int)query('SELECT is_active FROM users WHERE id = ?', [$match[1]])->fetchColumn();
                audit($user['id'], $active ? 'employee_activated' : 'employee_deactivated', 'user', $match[1]);
                db()->commit();
                flash('success', 'Status akun pegawai berhasil diperbarui.');
                redirect('/admin/pegawai');
            } catch (Throwable $exception) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $exception;
            }
        }

        if ($path === '/admin/audit' && $method === 'GET') {
            require_role('admin');
            $logs = query('SELECT a.id, a.action, a.entity_type, a.entity_id, a.details, a.ip_address, a.created_at, u.name AS user_name, u.email AS user_email FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 150')->fetchAll();
            render('audit', ['title' => 'Audit Log', 'logs' => $logs]);
        }

        if ($method === 'GET' && $path === '/' && empty($_SESSION['user'])) {
            redirect('/login');
        }
        render('error', ['title' => 'Halaman tidak ditemukan', 'message' => 'Halaman yang Anda cari tidak tersedia.'], 404);
    } catch (Throwable $exception) {
        error_log('Permintaan SIPRINT gagal: ' . $exception->getMessage());
        render('error', ['title' => 'Terjadi gangguan', 'message' => 'Permintaan belum dapat diproses. Silakan coba kembali.'], 500);
    }
}

require_once SIPRINT_ROOT . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'layout.php';

app();
