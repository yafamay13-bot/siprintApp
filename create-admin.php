<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Salin config.example.php menjadi config.php dan atur koneksi database terlebih dahulu.\n");
    exit(1);
}
$config = require $configPath;
if (!is_array($config) || strlen((string)($config['session_secret'] ?? '')) < 32
    || str_contains((string)($config['session_secret'] ?? ''), 'replace-with-a-random-secret')) {
    fwrite(STDERR, "Konfigurasi tidak valid: session_secret wajib minimal 32 karakter.\n");
    exit(1);
}

$name = trim((string)(getenv('ADMIN_NAME') ?: ''));
$email = strtolower(trim((string)(getenv('ADMIN_EMAIL') ?: '')));
$password = (string)(getenv('ADMIN_PASSWORD') ?: '');
if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 120
    || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || mb_strlen($password, 'UTF-8') < 16 || strlen($password) > 72) {
    fwrite(STDERR, "Set ADMIN_NAME, ADMIN_EMAIL, dan ADMIN_PASSWORD (minimal 16 karakter, maksimal 72 byte) di environment.\n");
    exit(1);
}

try {
    $host = (string)($config['db_host'] ?? '127.0.0.1');
    $port = (int)($config['db_port'] ?? 3306);
    $database = (string)($config['db_name'] ?? 'siprint');
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        (string)($config['db_user'] ?? ''),
        (string)($config['db_password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $existingAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($existingAdmin > 0) {
        fwrite(STDERR, "Administrator sudah ada; bootstrap hanya dapat membuat admin pertama.\n");
        exit(1);
    }
    $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $statement->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), 'admin']);
    fwrite(STDOUT, "Akun administrator pertama berhasil dibuat untuk {$email}.\n");
} catch (PDOException $exception) {
    fwrite(STDERR, $exception->getCode() === '23000'
        ? "Email sudah terdaftar.\n"
        : "Bootstrap gagal: " . $exception->getMessage() . "\n");
    exit(1);
}
