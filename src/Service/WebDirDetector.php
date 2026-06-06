<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Service;

class WebDirDetector
{
    private const CANDIDATES = ['public', 'web'];

    public function detect(string $projectRoot): ?string
    {
        // composer.json extra.public-dir is the canonical source
        $fromComposer = $this->detectFromComposerJson($projectRoot);

        if (null !== $fromComposer && is_dir($projectRoot . '/' . $fromComposer)) {
            return $fromComposer;
        }

        foreach (self::CANDIDATES as $dir) {
            if (is_dir($projectRoot . '/' . $dir)) {
                return $dir;
            }
        }

        return null;
    }

    public function detectFromComposerJson(string $projectRoot): ?string
    {
        $composerJson = $projectRoot . '/composer.json';

        if (!is_file($composerJson)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($composerJson), true);

        return isset($data['extra']['public-dir']) ? (string) $data['extra']['public-dir'] : null;
    }
}
