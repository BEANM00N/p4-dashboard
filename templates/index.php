<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\PerforceDashboard\AppInfo\Application::APP_ID, OCA\PerforceDashboard\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\PerforceDashboard\AppInfo\Application::APP_ID, OCA\PerforceDashboard\AppInfo\Application::APP_ID . '-main');

?>

<div id="perforcedashboard"></div>
