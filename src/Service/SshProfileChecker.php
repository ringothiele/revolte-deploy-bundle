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

    /**
     * All SSH config files to consider: the main config plus config.d/*.conf.
     * Inside the ddev container the profiles live in ~/.ssh/config.d/revolte.conf
     * (via homeadditions), so the main file alone is not enough.
     *
     * @return list<string>
     */
    public function getSshConfigFiles(): array
    {
        $files = [];
        $main = $this->getSshConfigPath();

        if (is_file($main)) {
            $files[] = $main;
        }

        foreach (glob(\dirname($main) . '/config.d/*.conf') ?: [] as $file) {
            if (is_file($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    public function profileExists(string $profileName): bool
    {
        foreach ($this->getSshConfigFiles() as $file) {
            $content = (string) file_get_contents($file);

            if (preg_match('/^Host\s+' . preg_quote($profileName, '/') . '\s*$/m', $content)) {
                return true;
            }
        }

        return false;
    }

    public function getProfileDetails(string $profileName): ?array
    {
        foreach ($this->getSshConfigFiles() as $file) {
            $details = $this->parseProfileFromFile($file, $profileName);

            if (null !== $details) {
                return $details;
            }
        }

        return null;
    }

    private function parseProfileFromFile(string $file, string $profileName): ?array
    {
        $lines = explode("\n", (string) file_get_contents($file));
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
        return implode(PHP_EOL, [
            'Am einfachsten per Setup-Script (legt Key, Profile und Config-Eintrag an):',
            sprintf('    vendor/bin/revolte-ssh-setup %s', $environment),
            'Oder manuell in ~/.ssh/config:',
            sprintf('Host %s', $profileName),
            '    HostName <server-ip-oder-hostname>',
            '    User <ssh-user>',
            '    Port 22',
            '    IdentityFile ~/.ssh/<kuerzel>_<server-account>_ed25519',
            '    IdentitiesOnly yes',
        ]);
    }
}
