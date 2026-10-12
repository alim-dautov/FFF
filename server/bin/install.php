<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (in_array('--fresh', $argv ?? [], true)) {
    $config = load_config();
    if (($config['driver'] ?? 'sqlite') === 'sqlite' && is_file($config['sqlite_path'])) {
        unlink($config['sqlite_path']);
    }
}

[$db] = boot_app();
$count = $db->one('SELECT COUNT(*) AS c FROM customers')['c'];
fwrite(STDOUT, "FOODFORFIT CRM готова. Клиентов: {$count}\n");
