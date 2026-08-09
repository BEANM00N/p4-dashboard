<?php

declare(strict_types=1);

namespace OCA\PerforceDashboard\Service;

use OCP\IConfig;

class PerforceService {

    private IConfig $config;
    private string $envPrefix = 'export P4TRUST=/tmp/.p4trust; export P4TICKETS=/tmp/.p4tickets;';
    
    // Absolute path fallback to ensure execution works even if symlinks are wiped
    private string $p4Binary = '/var/www/html/custom_apps/perforcedashboard/p4';

    public function __construct(IConfig $config) {
        $this->config = $config;
        if (!file_exists($this->p4Binary)) {
            $this->p4Binary = 'p4';
        }
    }

    private function execP4(string $args): array {
        $server = $this->config->getAppValue('perforcedashboard', 'p4_server', '172.16.3.1:1665');
        $user = $this->config->getAppValue('perforcedashboard', 'p4_user', 'Josh');
        $password = $this->config->getAppValue('perforcedashboard', 'p4_password', '');

        if (empty($server)) {
            return ['error' => 'Perforce server address is not configured.'];
        }

        if (str_starts_with($server, 'ssl:')) {
            $trustCmd = sprintf(
                '%s %s -p %s trust -y -f < /dev/null > /dev/null 2>&1', 
                $this->envPrefix, 
                $this->p4Binary, 
                escapeshellarg($server)
            );
            shell_exec($trustCmd);
        }

        $cmd = sprintf(
            '%s %s -p %s -u %s %s %s < /dev/null 2>&1',
            $this->envPrefix,
            $this->p4Binary,
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

    public function getChangelists(string $status = 'pending'): array {
        if ($status === 'submitted') {
            return $this->getSubmittedChangelistsWithCache();
        }

        return $this->getPendingChangelists();
    }

    private function getSubmittedChangelistsWithCache(): array {
        $result = $this->execP4("changes -s submitted -m 15");

        if (isset($result['error']) || empty($result['output'])) {
            return [];
        }

        $cacheRaw = $this->config->getAppValue('perforcedashboard', 'submitted_cache', '{}');
        $cache = json_decode($cacheRaw, true) ?: [];

        $clHeaders = [];
        $uncachedIds = [];

        foreach ($result['output'] as $line) {
            if (preg_match('/^Change (\d+) on (\S+) by ([^@\s]+)@\S+ \'(.*)\'/', $line, $matches)) {
                $clId = (int)$matches[1];
                $clHeaders[$clId] = [
                    'id' => $clId,
                    'owner' => $matches[3],
                    'description' => $matches[4],
                    'status' => 'submitted',
                    'timestamp' => $matches[2]
                ];

                if (!isset($cache[$clId])) {
                    $uncachedIds[] = $clId;
                }
            }
        }

        if (!empty($uncachedIds)) {
            $uncachedStr = implode(' ', $uncachedIds);
            $describeResult = $this->execP4("describe -s {$uncachedStr}");

            $maxFiles = 500;
            $currentClId = null;

            foreach ($describeResult['output'] as $fileLine) {
                if (preg_match('/^Change (\d+) by /', $fileLine, $m)) {
                    $currentClId = (int)$m[1];
                    if (isset($clHeaders[$currentClId])) {
                        $clHeaders[$currentClId]['files'] = [];
                        $clHeaders[$currentClId]['totalFiles'] = 0;
                        $clHeaders[$currentClId]['truncated'] = false;
                    }
                    continue;
                }

                if ($currentClId && isset($clHeaders[$currentClId])) {
                    if (preg_match('/(?:\.\.\.\s+)?(\/\/.*?)#\d+/', $fileLine, $fileMatches)) {
                        $clHeaders[$currentClId]['totalFiles']++;
                        if (count($clHeaders[$currentClId]['files']) < $maxFiles) {
                            $clHeaders[$currentClId]['files'][] = $fileMatches[1];
                        } else {
                            $clHeaders[$currentClId]['truncated'] = true;
                        }
                    }
                }
            }

            foreach ($uncachedIds as $id) {
                if (isset($clHeaders[$id])) {
                    $cache[$id] = $clHeaders[$id];
                }
            }

            $this->config->setAppValue('perforcedashboard', 'submitted_cache', json_encode($cache));
        }

        $finalList = [];
        foreach ($clHeaders as $id => $header) {
            if (isset($cache[$id])) {
                $finalList[] = $cache[$id];
            }
        }

        return $finalList;
    }

    private function getPendingChangelists(): array {
        $result = $this->execP4("changes -s pending -m 15");

        if (isset($result['error']) || empty($result['output'])) {
            return [];
        }

        $clMap = [];
        foreach ($result['output'] as $line) {
            if (preg_match('/^Change (\d+) on (\S+) by ([^@\s]+)@\S+ \*pending\* \'(.*)\'/', $line, $matches)) {
                $clId = (int)$matches[1];
                $clMap[$clId] = [
                    'id' => $clId,
                    'owner' => $matches[3],
                    'description' => $matches[4],
                    'status' => 'pending',
                    'files' => [],
                    'totalFiles' => 0,
                    'truncated' => false,
                    'timestamp' => $matches[2]
                ];
            }
        }

        if (!empty($clMap)) {
            $openedResult = $this->execP4("opened -a -m 500");
            foreach ($openedResult['output'] as $fileLine) {
                if (preg_match('/^(\/\/.*?)(?:#\d+)?\s+-\s+\w+\s+.*?\bchange\s+(\d+)/', $fileLine, $m)) {
                    $filePath = $m[1];
                    $clId = (int)$m[2];
                    if (isset($clMap[$clId])) {
                        $clMap[$clId]['totalFiles']++;
                        if (count($clMap[$clId]['files']) < 500) {
                            $clMap[$clId]['files'][] = $filePath;
                        }
                    }
                }
            }
        }

        return array_values($clMap);
    }

    public function getCheckouts(): array {
        $result = $this->execP4('opened -a -m 500');

        if (isset($result['error']) || empty($result['output'])) {
            return [];
        }

        $userMap = [];
        $maxFilesPerUser = 100;

        foreach ($result['output'] as $line) {
            if (preg_match('/^(\/\/.*?)(?:#\d+)?\s+-\s+(\w+)\s+.*?\bby\s+([^@\s]+)@(\S+)/', $line, $matches)) {
                $filePath  = $matches[1];
                $action    = $matches[2];
                $userName  = $matches[3];
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
}