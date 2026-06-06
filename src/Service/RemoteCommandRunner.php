<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Service;

use Symfony\Component\Process\Process;

class RemoteCommandRunner
{
    public function run(string $sshProfile, string $remoteCommand, ?callable $outputCallback = null, int $timeout = 300): void
    {
        $process = Process::fromShellCommandline(
            sprintf('ssh -o BatchMode=yes %s %s', escapeshellarg($sshProfile), escapeshellarg($remoteCommand)),
            timeout: $timeout,
        );

        $process->run($outputCallback);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    public function capture(string $sshProfile, string $remoteCommand, int $timeout = 30): string
    {
        $output = '';

        $this->run($sshProfile, $remoteCommand, function (string $type, string $buffer) use (&$output): void {
            $output .= $buffer;
        }, $timeout);

        return trim($output);
    }

    public function test(string $sshProfile, string $remoteCommand): bool
    {
        try {
            $this->capture($sshProfile, $remoteCommand);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
