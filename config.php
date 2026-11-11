<?php

return [
    'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port' => (int)(getenv('DB_PORT') ?: 3306),
    'db_name' => getenv('DB_NAME') ?: 'siprint',
    'db_user' => getenv('DB_USER') ?: 'root',
    'db_password' => getenv('DB_PASSWORD') ?: '',
    'session_secret' => getenv('SESSION_SECRET') ?: 'SESSION_SECRET=SIPRINT_UMC_2026_SecureSession_Key!',
    'upload_dir' => getenv('UPLOAD_DIR') ?: (__DIR__ . DIRECTORY_SEPARATOR . 'storage'),
    'secure_cookies' => filter_var(getenv('SECURE_COOKIES') ?: 'false', FILTER_VALIDATE_BOOLEAN),
];
