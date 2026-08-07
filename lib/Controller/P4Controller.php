<?php

declare(strict_types=1);

namespace OCA\PerforceDashboard\Controller;

use OCA\PerforceDashboard\Service\PerforceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class P4Controller extends Controller {

    private IConfig $config;
    private PerforceService $p4Service;

    public function __construct(string $appName, IRequest $request, IConfig $config, PerforceService $p4Service) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->p4Service = $p4Service;
    }

    public function getChangelists(): DataResponse {
        $pending = $this->p4Service->getChangelists('pending');
        $submitted = $this->p4Service->getChangelists('submitted');

        return new DataResponse(array_merge($pending, $submitted));
    }

    public function getCheckouts(): DataResponse {
        $checkouts = $this->p4Service->getCheckouts();
        return new DataResponse($checkouts);
    }

    public function getSettings(): DataResponse {
        return new DataResponse([
            'server' => $this->config->getAppValue('perforcedashboard', 'p4_server', 'ssl:perforce.madmoonserver.com:1666'),
            'user' => $this->config->getAppValue('perforcedashboard', 'p4_user', ''),
            'password' => $this->config->getAppValue('perforcedashboard', 'p4_password', ''),
        ]);
    }

    public function saveSettings(): DataResponse {
        $server = $this->request->getParam('server', '');
        $user = $this->request->getParam('user', '');
        $password = $this->request->getParam('password', '');

        $this->config->setAppValue('perforcedashboard', 'p4_server', $server);
        $this->config->setAppValue('perforcedashboard', 'p4_user', $user);
        $this->config->setAppValue('perforcedashboard', 'p4_password', $password);

        return new DataResponse([
            'status' => 'success',
            'message' => 'Perforce connection settings saved successfully!'
        ]);
    }
}