<?php

declare(strict_types=1);

namespace OCA\PerforceDashboard\Service;

use OCP\IConfig;

class PerforceService {

    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    /**
     * Executes a P4 CLI command using saved credentials and auto-trusts SSL
     */
    private function execP4(string $args): array {
        $server = $this->config->getAppValue('perforcedashboard', 'p4_server', '');
        $user = $this->config->getAppValue('perforcedashboard', 'p4_user', '');
        $password = $this->config->getAppValue('perforcedashboard', 'p4_password', '');

        if (empty($server)) {
            return ['error' => 'Perforce server address is not configured.'];
        }

        // Auto-trust SSL certificate if using ssl: protocol
        if (str_starts_with($server, 'ssl:')) {
            exec(sprintf('export P4TRUST=/tmp/.p4trust; p4 -p %s trust -y 2>&1', escapeshellarg($server)));
        }

        // Ensure trust and ticket stores in /tmp are always passed to every execution
        $cmd = sprintf(
            'export P4TRUST=/tmp/.p4trust; export P4TICKETS=/tmp/.p4tickets; p4 -p %s -u %s %s %s 2>&1',
            escapeshellarg($server),
            escapeshellarg($user),
            !empty($password) ? '-P ' . escapeshellarg($password) : '',
            $args
        );

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        return [
            'code' => $returnCode,
            'output' => $output
        ];
    }

    /**
     * Fetches changelists based on status ('pending' or 'submitted')
     */
public function getCheckouts(): array {
        // -a queries opened files across ALL workspaces and ALL users
        $result = $this->execP4('opened -a');

        if (isset($result['error']) || empty($result['output'])) {
            return [];
        }

        $userMap = [];
        $maxFilesPerUser = 500;

        foreach ($result['output'] as $line) {
            // Matches format: //depot/path/file.uasset#1 - edit default change (binary) by User@Workspace
            if (preg_match('/^(\/\/.*?)(?:#\d+)?\s+-\s+(\w+)\s+(?:default\s+change|change\s+\d+).*?\s+by\s+([^@\s]+)@(\S+)/', $line, $matches)) {
                $filePath = $matches[1];
                $action = $matches[2]; // edit, add, or delete
                $userName = $matches[3];
                $workspace = $matches[4];

                if (!isset($userMap[$userName])) {
                    $userMap[$userName] = [
                        'user' => $userName,
                        'workspace' => $workspace,
                        'files' => []
                    ];
                }

                if (count($userMap[$userName]['files']) < $maxFilesPerUser) {
                    $userMap[$userName]['files'][] = [
                        'path' => $filePath,
                        'action' => $action
                    ];
                }
            }
        }

        return array_values($userMap);
    }