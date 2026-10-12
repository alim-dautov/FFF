<?php
declare(strict_types=1);

function load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function load_config(): array
{
    $root = dirname(__DIR__, 2);
    load_env_file($root . '/.env');
    $config = [
        'driver' => 'sqlite',
        'sqlite_path' => dirname(__DIR__) . '/data/fff.sqlite',
        'mysql' => [
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'foodforfit',
            'user' => 'root',
            'password' => '',
        ],
        'grok_api_key' => '',
        'grok_model' => 'grok-4',
        'whatsapp_token' => '',
        'whatsapp_phone_id' => '',
        'kaspi_api_key' => '',
        'kaspi_webhook_secret' => '',
        'vapid_public' => '',
        'vapid_private' => '',
        'vapid_subject' => 'mailto:admin@foodforfit.kz',
        'app_url' => 'http://localhost:5173',
        'debug' => true,
        'payment_provider' => 'manual',
    ];
    $file = __DIR__ . '/../api/config.php';
    if (is_file($file)) {
        $local = require $file;
        if (is_array($local)) {
            $config = array_replace_recursive($config, $local);
        }
    }
    $map = [
        'DB_DRIVER' => 'driver',
        'SQLITE_PATH' => 'sqlite_path',
        'GROK_API_KEY' => 'grok_api_key',
        'GROK_MODEL' => 'grok_model',
        'WHATSAPP_TOKEN' => 'whatsapp_token',
        'WHATSAPP_PHONE_ID' => 'whatsapp_phone_id',
        'KASPI_API_KEY' => 'kaspi_api_key',
        'KASPI_WEBHOOK_SECRET' => 'kaspi_webhook_secret',
        'VAPID_PUBLIC' => 'vapid_public',
        'VAPID_PRIVATE' => 'vapid_private',
        'VAPID_SUBJECT' => 'vapid_subject',
        'APP_URL' => 'app_url',
    ];
    foreach ($map as $env => $key) {
        $value = getenv($env);
        if ($value !== false && $value !== '') {
            $config[$key] = $value;
        }
    }
    if (getenv('MYSQL_HOST')) {
        $config['mysql']['host'] = getenv('MYSQL_HOST');
    }
    if (getenv('MYSQL_PORT')) {
        $config['mysql']['port'] = (int) getenv('MYSQL_PORT');
    }
    if (getenv('MYSQL_DATABASE')) {
        $config['mysql']['database'] = getenv('MYSQL_DATABASE');
    }
    if (getenv('MYSQL_USER')) {
        $config['mysql']['user'] = getenv('MYSQL_USER');
    }
    if (getenv('MYSQL_PASSWORD') !== false && getenv('MYSQL_PASSWORD') !== '') {
        $config['mysql']['password'] = getenv('MYSQL_PASSWORD');
    }
    if (getenv('APP_DEBUG') !== false && getenv('APP_DEBUG') !== '') {
        $config['debug'] = getenv('APP_DEBUG') === '1';
    }
    return $config;
}

function connect_db(array $config): Db
{
    $driver = $config['driver'] ?? 'sqlite';
    if ($driver === 'mysql') {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('Расширение pdo_mysql не установлено. Локально используйте DB_DRIVER=sqlite.');
        }
        $m = $config['mysql'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $m['host'], (int) $m['port'], $m['database']);
        $pdo = new PDO($dsn, $m['user'], $m['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return new Db($pdo, 'mysql');
    }
    $path = $config['sqlite_path'];
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return new Db($pdo, 'sqlite');
}
