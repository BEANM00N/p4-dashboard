<?php

declare(strict_types=1);

namespace OCA\PerforceDashboard\Service;

use OCP\IConfig;

class PerforceService {

    private IConfig $config;
    
    // Define the environment prefix to guarantee Context for the www-data user
    private string $envPrefix = 'export P4TRUST=/tmp/.p4trust; export P4TICKETS=/tmp/.p4tickets;';
    private string $p4Binary = 'p4'; // Update to absolute path like '/var/www/html/custom_apps/perforcedashboard/p4' if 'p4' is not in PATH

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    /**
     * 1. Automate Ticket Generation (The "Login" Fix)
     * Can be called by your Settings Controller when a user updates their password.
     */
    public function generateTicket(string $server, string $user, string $rawPassword): string {
        // Auto-trust SSL first so the login doesn't fail
        if (str_starts_with($server, 'ssl:')) {
            shell_exec(sprintf('%s %s -p %s trust -y -f < /dev/null > /dev/null 2>&1', $this->envPrefix, $this->p4Binary, escapeshellarg($server)));
        }

        // Pipe the password into p4 login -p to bypass the interactive prompt
        $cmd = sprintf(
            "echo %s | %s %s -p %s -u %s login -p 2>&1",
            escapeshellarg($rawPassword),
            $this->envPrefix,
            $this->p4Binary,
            escapeshellarg($server),
            escapeshellarg($user)
        );
        
        $output = shell_exec($cmd);
        
        // Extract the 32-character ticket hash from the output
        if (preg_match('/([A-F0-9]{32})/', $output, $matches)) {
            return $matches[1]; // Save this returned string to Nextcloud config as p4_password
        }
        
        throw new \Exception("Failed to generate Perforce ticket. Output: " . $output);
    }

    /**
     * Executes a P4 CLI command using saved credentials, auto-trusts SSL, and prevents hangs
     */
    private function execP4(string $args): array {
        $server = $this->config->getAppValue('perforcedashboard', 'p4_server', '');
        $user = $this->config->getAppValue('perforcedashboard', 'p4_user', '');
        $password = $this->config->getAppValue('perforcedashboard', 'p4_password', '');

        if (empty($server)) {
            return ['error' => 'Perforce server address is not configured.'];
        }

        // 3. Auto-Trust SSL Connections silently
        if (str_starts_with($server, 'ssl:')) {
            $trustCmd = sprintf(
                '%s %s -p %s trust -y -f < /dev/null > /dev/null 2>&1', 
                $this->envPrefix, 
                $this->p4Binary, 
                escapeshellarg($server)
            );
            shell_exec($trustCmd);
        }

        // 2 & 4. Inject Variables and Append < /dev/null to prevent infinite hangs
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