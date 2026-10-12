<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Almaty');

require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Util.php';
require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Schema.php';
require_once __DIR__ . '/services/AuthService.php';
require_once __DIR__ . '/services/CommService.php';
require_once __DIR__ . '/services/AutomationService.php';
require_once __DIR__ . '/services/BillingService.php';
require_once __DIR__ . '/services/CrmService.php';
require_once __DIR__ . '/services/OpsService.php';
require_once __DIR__ . '/services/PeopleService.php';
require_once __DIR__ . '/services/InsightService.php';
require_once __DIR__ . '/services/WhatsAppService.php';
require_once __DIR__ . '/ai/ToolRegistry.php';
require_once __DIR__ . '/ai/GrokProvider.php';
require_once __DIR__ . '/ai/Agent.php';
require_once __DIR__ . '/seed/Seed.php';

function boot_app(): array
{
    $config = load_config();
    $db = connect_db($config);
    Schema::install($db);
    Seed::run($db, $config);
    return [$db, $config];
}
