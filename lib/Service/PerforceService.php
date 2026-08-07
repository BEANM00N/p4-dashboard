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
            exec(sprintf('p4 -p %s trust -y 2>&1', escapeshellarg($server)));
        }

        // Build command
        $cmd = sprintf(
            'p4 -p %s -u %s %s %s 2>&1',
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
    public function getChangelists(string $status = 'pending'): array {
        $statusArg = ($status === 'submitted') ? '-s submitted' : '-s pending';
        $result = $this->execP4("changes {$statusArg} -m 20");

        if (isset($result['error']) || empty($result['output'])) {
            return [];
        }

        $changelists = [];
        foreach ($result['output'] as $line) {
            // Match pending or submitted change line
            // e.g. "Change 12345 on 2026/08/07 by user@workspace *pending* 'description...'"
            // e.g. "Change 12340 on 2026/08/07 by user@workspace 'description...'"
            if (preg_match('/^Change (\d+) on (\S+) by ([^@\s]+)@\S+ (?:(\*pending\*) )?\'(.*)\'/', $line, $matches)) {
                $clId = (int)$matches[1];
                $date = $matches[2];
                $user = $matches[3];
                $desc = $matches[5];

                // Fetch files for this changelist
                $filesCmd = ($status === 'pending') ? 'opened -c ' . $clId : 'describe -s ' . $clId;
                $filesResult = $this->execP4($filesCmd);
                $files = [];

                foreach ($filesResult['output'] as $fileLine) {
                    if (preg_match('/^\.\.\.\s+(\/\/depot\/[^\s#]+)/', $fileLine, $fileMatches) || 
                        preg_match('/^(\/\/depot\/[^\s#]+)/', $fileLine, $fileMatches)) {
                        $files[] = $fileMatches[1];
                    }
                }

                $changelists[] = [
                    'id' => $clId,
                    'owner' => $user,
                    'description' => $desc,
                    'status' => $status,
                    'files' => array_unique($files),
                    'timestamp' => $date
                ];
            }
        }

        return $changelists;
    }
}