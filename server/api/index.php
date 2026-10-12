<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/http/Kernel.php';

[$db, $config] = boot_app();
Kernel::handle($db, $config);
