<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

[$db, $config] = boot_app();

$admin = AuthService::attempt($db, 'admin@foodforfit.kz', Seed::PASSWORD, '127.0.0.1');
$file = dirname(__DIR__, 2) . '/clients_oct2026.csv';
if (!is_file($file)) {
    fwrite(STDERR, "Missing {$file}\n");
    exit(1);
}

$dir = dirname(__DIR__) . '/data/imports';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$token = bin2hex(random_bytes(16));
$stored = $dir . '/' . $token . '.csv';
copy($file, $stored);

$uploadFake = [
    'name' => 'clients_oct2026.csv',
    'type' => 'text/csv',
    'tmp_name' => $stored,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($stored),
];

// upload expects move_uploaded_file — bypass by placing file and calling preview/commit directly
[$headers, $rows] = (function () use ($stored) {
    $ref = new ReflectionClass(ClientImportService::class);
    $m = $ref->getMethod('readFile');
    $m->setAccessible(true);
    return $m->invoke(null, $stored, 'csv');
})();

$mapMethod = new ReflectionMethod(ClientImportService::class, 'autoMap');
$mapMethod->setAccessible(true);
$mapping = $mapMethod->invoke(null, $headers);

$preview = ClientImportService::preview($db, $admin, [
    'token' => $token,
    'mapping' => $mapping,
    'duplicate_mode' => [],
    'existing_mode' => 'create_subscription',
    'filename' => 'clients_oct2026.csv',
]);

echo "Preview errors: " . count($preview['errors']) . PHP_EOL;
echo "Counts: " . json_encode($preview['counts'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
if ($preview['errors']) {
    foreach (array_slice($preview['errors'], 0, 10) as $e) {
        echo "  row {$e['row']}: {$e['message']}\n";
    }
    exit(1);
}

$result = ClientImportService::commit($db, $admin, [
    'token' => $token,
    'mapping' => $mapping,
    'duplicate_mode' => [],
    'existing_mode' => 'create_subscription',
    'filename' => 'clients_oct2026.csv',
]);

echo "Commit: " . json_encode($result['counts'], JSON_UNESCAPED_UNICODE) . PHP_EOL;

$subs = (int) $db->one('SELECT COUNT(*) AS c FROM client_subscriptions')['c'];
$clients = (int) $db->one('SELECT COUNT(DISTINCT customer_id) AS c FROM client_subscriptions')['c'];
$active = (int) $db->one("SELECT COUNT(*) AS c FROM client_subscriptions WHERE status = 'active'")['c'];
$ended = (int) $db->one("SELECT COUNT(*) AS c FROM client_subscriptions WHERE status = 'ended'")['c'];
$paused = (int) $db->one("SELECT COUNT(*) AS c FROM client_subscriptions WHERE status = 'paused'")['c'];
$premium = (int) $db->one("SELECT COUNT(*) AS c FROM client_subscriptions WHERE line = 'Премиум'")['c'];
$standard = (int) $db->one("SELECT COUNT(*) AS c FROM client_subscriptions WHERE line = 'Стандарт'")['c'];

echo "subs={$subs} clients={$clients} active={$active} ended={$ended} paused={$paused} premium={$premium} standard={$standard}\n";

$ok = $subs === 183 && $clients === 175 && $active === 120 && $ended === 54 && $paused === 9 && $premium === 87 && $standard === 96;
echo $ok ? "STATS OK\n" : "STATS MISMATCH\n";
exit($ok ? 0 : 1);
