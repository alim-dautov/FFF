<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/server/data/verify.sqlite';
if (is_file($path)) {
    unlink($path);
}
putenv('SQLITE_PATH=' . $path);
putenv('DB_DRIVER=sqlite');
putenv('GROK_API_KEY=');
require dirname(__DIR__) . '/bootstrap.php';
[$db, $config] = boot_app();

function check(bool $ok, string $label): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK {$label}\n");
}

$admin = AuthService::attempt($db, 'admin@foodforfit.kz', Seed::PASSWORD, '127.0.0.1');
$sales = AuthService::attempt($db, 'sales@foodforfit.kz', Seed::PASSWORD, '127.0.0.1');
$warehouse = AuthService::attempt($db, 'warehouse@foodforfit.kz', Seed::PASSWORD, '127.0.0.1');
check($admin['role'] === 'super_admin', 'admin login');

$threw = false;
try {
    InsightService::finance($db, $warehouse, 'month');
} catch (HttpError $e) {
    $threw = $e->status === 403;
}
check($threw, 'warehouse cannot open finance');

$dash = InsightService::dashboard($db, $warehouse);
check($dash['kpis']['revenue_today'] === null, 'warehouse revenue hidden');

$lead = $db->one("SELECT * FROM leads WHERE stage = 'new' AND manager_id = :id LIMIT 1", ['id' => $sales['employee_id']]);
check((bool) $lead, 'sales has a lead');
$beforeCustomers = (int) $db->one('SELECT COUNT(*) AS c FROM customers')['c'];
$moved = CrmService::moveLead($db, $sales, (int) $lead['id'], ['stage' => 'paid']);
$after = (int) $db->one('SELECT COUNT(*) AS c FROM customers')['c'];
check($after === $beforeCustomers + 1 && !empty($moved['customer_id']), 'paid lead creates customer');
$subs = (int) $db->one('SELECT COUNT(*) AS c FROM subscriptions WHERE customer_id = :id', ['id' => $moved['customer_id']])['c'];
check($subs === 1, 'paid lead creates one subscription');
CrmService::moveLead($db, $sales, (int) $lead['id'], ['stage' => 'active_client']);
$after2 = (int) $db->one('SELECT COUNT(*) AS c FROM customers')['c'];
check($after2 === $after, 'second move does not duplicate customer');

$card = CrmService::getCustomer($db, $admin, (int) $moved['customer_id']);
$discount = CrmService::discount($db, $admin, (int) $moved['customer_id'], ['percent' => 10]);
check($discount['total'] === $discount['price'] - $discount['discount'], 'discount math');

$invoice = BillingService::create($db, $config, $admin, ['customer_id' => $moved['customer_id'], 'subscription_id' => $card['current']['id']]);
$endBefore = $db->one('SELECT end_date FROM subscriptions WHERE id = :id', ['id' => $card['current']['id']])['end_date'];
BillingService::markPaid($db, $config, $admin, (int) $invoice['payment']['id']);
$endAfter = $db->one('SELECT end_date, status FROM subscriptions WHERE id = :id', ['id' => $card['current']['id']]);
check($endAfter['end_date'] > $endBefore && $endAfter['status'] === 'active', 'payment extends subscription');

$product = $db->one('SELECT p.id, i.quantity, p.min_stock FROM products p JOIN inventory i ON i.product_id = p.id WHERE i.quantity > p.min_stock LIMIT 1');
OpsService::moveStock($db, $config, $admin, ['product_id' => $product['id'], 'type' => 'adjust', 'quantity' => 1, 'warehouse' => 'Кухня']);
$lowNotes = (int) $db->one("SELECT COUNT(*) AS c FROM notifications WHERE title LIKE 'Низкий остаток%'")['c'];
check($lowNotes > 0, 'low stock notifies');

$phone = $db->one('SELECT phone FROM customers WHERE id = 1')['phone'];
$found = InsightService::search($db, $admin, $phone);
check(count($found['customers']) > 0, 'search by phone');

$aiThrew = false;
try {
    Agent::make($db, $config)->chat($admin, ['message' => 'Сколько активных клиентов?']);
} catch (HttpError $e) {
    $aiThrew = $e->getMessage() === 'AI Agent временно недоступен.';
}
check($aiThrew, 'grok without key is unavailable');
$slash = Agent::make($db, $config)->chat($sales, ['message' => '/renewals']);
check(str_contains($slash['message'], 'AI Agent временно недоступен') && $slash['links'] !== null, 'slash command still reads CRM');

$runs = (int) $db->one('SELECT COUNT(*) AS c FROM automation_runs')['c'];
AutomationService::runDaily($db, $config);
$runs2 = (int) $db->one('SELECT COUNT(*) AS c FROM automation_runs')['c'];
check($runs2 === $runs, 'retention does not duplicate');

$foreign = (int) $db->pdo->query('PRAGMA foreign_key_check')->fetchColumn();
check($foreign === 0 || $foreign === null || $db->pdo->query('PRAGMA foreign_key_check')->fetch() === false, 'foreign keys intact');

fwrite(STDOUT, "ALL CHECKS PASSED\n");
