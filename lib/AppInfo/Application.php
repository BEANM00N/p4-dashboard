<?php

namespace OCA\PerforceDashboard\AppInfo;

use OCP\AppFramework\App;

class Application extends App {
    public const APP_ID = 'perforcedashboard';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }
}