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

        if (str_starts_with($server, 'ssl:')) {
            exec(sprintf('export P4TRUST=/tmp/.p4trust; p4 -p %s trust -y 2>&1', escapeshellarg($server)));
        }

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
     * Fetches changelists with smart persistent caching for submitted changes
     */
    public function getChangelists(string $status = 'pending'): array {
        if ($status === 'submitted') {
            return $this->getSubmittedChangelistsWithCache();
        }

        return $this->getPendingChangelists();
    }

    /**
     * Fetches submitted changelists - only queries Perforce for UNCACHED changelist IDs
     */
    private function getSubmittedChangelistsWithCache(): array {
        $result = $this->execP4("changes -s submitted -m 15");

        if (isset($result['error']) || empty($result['output'])) {
            return [];
        }

        // Load cached submitted changelists
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

        // ONLY query Perforce `describe -s` for BRAND NEW submitted changelists!
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

            // Save new entries into cache
            foreach ($uncachedIds as $id) {
                if (isset($clHeaders[$id])) {
                    $cache[$id] = $clHeaders[$id];
                }
            }

            $this->config->setAppValue('perforcedashboard', 'submitted_cache', json_encode($cache));
        }

        // Build list in order of latest changes
        $finalList = [];
        foreach ($clHeaders as $id => $header) {
            if (isset($cache[$id])) {
                $finalList[] = $cache[$id];
            }
        }

        return $finalList;
    }

    /**
     * Fetches active pending changelists
     */
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

    /**
     * Fetches all currently checked-out files across all team workspaces
     */
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