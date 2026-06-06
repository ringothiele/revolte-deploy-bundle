<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Service;

class SshProfileChecker
{
    public function getSshConfigPath(): string
    {
        $home = $_SERVER['HOME'] ?? (function_exists('posix_getuid')
            ? (posix_getpwuid(posix_getuid())['dir'] ?? '~')
            : '~');

        return $home . '/.ssh/config';
    }

    public function sshConfigExists(): bool
    {
        return is_file($this->getSshConfigPath());
    }

    public function profileExists(string $profileName): bool
    {
        if (!$this->sshConfigExists()) {
            return false;
        }

        $content = (string) file_get_contents($this->getSshConfigPath());

        return (bool) preg_match('/^Host\s+' . preg_quote($profileName, '/') . '\s*$/m', $content);
    }

    public function getProfileDetails(string $profileName): ?array
    {
        if (!$this->sshConfigExists()) {
            return null;
        }

        $lines = explode("\n", (string) file_get_contents($this->getSshConfigPath()));
        $inBlock = false;
        $details = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^Host\s+(.+)$/i', $trimmed, $m)) {
                if ($inBlock) {
                    break;
                }
                if (trim($m[1]) === $profileName) {
                    $inBlock = true;
                }
                continue;
            }

            if ($inBlock && preg_match('/^(\w+)\s+(.+)$/i', $trimmed, $m)) {
                $details[strtolower($m[1])] = trim($m[2]);
            }
        }

        return $inBlock ? $details : null;
    }

    public function testConnection(string $profileName, int $timeout = 10): bool
    {
        $process = new \Symfony\Component\Process\Process(
            ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=' . $timeout, $profileName, 'exit'],
            timeout: $timeout + 2,
        );
        $process->run();

        return $process->isSuccessful();
    }

    public function buildRecommendedSnippet(string $profileName, string $projectName, string $environment): string
    {
        $keyName = sprintf('%s_%s_ed25519', $projectName, $environment);

        return implode(PHP_EOL, [
            sprintf('Host %s', $profileName),
            sprintf('    HostName <server-ip-oder-hostname>'),
            sprintf('    User <ssh-user>'),
            sprintf('    Port 22'),
            sprintf('    IdentityFile ~/.ssh/revolte/%s', $keyName),
            sprintf('    IdentitiesOnly yes'),
        ]);
    }
}
