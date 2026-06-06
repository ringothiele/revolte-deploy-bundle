<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Service;

class ContaoVersionDetector
{
    public function detect(string $projectRoot): ?string
    {
        $lockFile = $projectRoot . '/composer.lock';

        if (!is_file($lockFile)) {
            return null;
        }

        $lock = json_decode((string) file_get_contents($lockFile), true);

        if (!is_array($lock)) {
            return null;
        }

        $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);

        foreach ($packages as $package) {
            if ('contao/core-bundle' === ($package['name'] ?? '')) {
                return $package['version'];
            }
        }

        foreach ($packages as $package) {
            if ('contao/manager-bundle' === ($package['name'] ?? '')) {
                return $package['version'];
            }
        }

        return null;
    }

    public function getMajorVersion(string $projectRoot): ?int
    {
        $version = $this->detect($projectRoot);

        if (null === $version) {
            return null;
        }

        $parts = explode('.', ltrim($version, 'v'));

        return (int) ($parts[0] ?? 0);
    }
}
