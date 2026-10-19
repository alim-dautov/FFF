<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

[$db] = boot_app();
$versions = $db->all('SELECT version FROM schema_migrations ORDER BY version');
echo "migrations: " . implode(',', array_column($versions, 'version')) . PHP_EOL;
$count = (int) $db->one('SELECT COUNT(*) AS c FROM customers')['c'];
$users = (int) $db->one('SELECT COUNT(*) AS c FROM users')['c'];
echo "customers: {$count}, users: {$users}" . PHP_EOL;
foreach ($db->all('SELECT id, full_name, name_key FROM customers LIMIT 5') as $row) {
    echo $row['id'] . '|' . $row['full_name'] . '|' . ($row['name_key'] ?? 'NULL') . PHP_EOL;
}
foreach (['client_subscriptions', 'import_batches', 'import_changes'] as $t) {
    $c = $db->one('SELECT COUNT(*) AS c FROM ' . $t);
    echo $t . '=' . $c['c'] . PHP_EOL;
}
$perm = $db->one('SELECT id FROM permissions WHERE code = :c', ['c' => 'customers.import']);
echo 'customers.import permission: ' . ($perm ? 'yes' : 'no') . PHP_EOL;
echo "OK\n";
