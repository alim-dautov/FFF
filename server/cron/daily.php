<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

[$db, $config] = boot_app();
$result = AutomationService::runDaily($db, $config);
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
