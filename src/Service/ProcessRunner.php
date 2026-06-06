<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Service;

use Symfony\Component\Process\Process;

class ProcessRunner
{
    public function capture(array $command, ?string $cwd = null, int $timeout = 30): string
    {
        $process = new Process($command, $cwd, timeout: $timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'Befehl "%s" fehlgeschlagen (Exit-Code %d): %s',
                implode(' ', $command),
                (int) $process->getExitCode(),
                trim($process->getErrorOutput() ?: $process->getOutput()),
            ));
        }

        return trim($process->getOutput());
    }

    public function captureOrNull(array $command, ?string $cwd = null, int $timeout = 10): ?string
    {
        $process = new Process($command, $cwd, timeout: $timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }

    public function isAvailable(string $binary): bool
    {
        $process = new Process(['which', $binary], timeout: 5);
        $process->run();

        return $process->isSuccessful();
    }
}
