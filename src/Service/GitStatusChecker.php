<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Service;

class GitStatusChecker
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function isInstalled(): bool
    {
        return $this->runner->isAvailable('git');
    }

    public function isRepository(string $projectRoot): bool
    {
        return null !== $this->runner->captureOrNull(
            ['git', '-C', $projectRoot, 'rev-parse', '--git-dir'],
        );
    }

    public function getCurrentBranch(string $projectRoot): string
    {
        // symbolic-ref works even on empty repos (no commits yet)
        $branch = $this->runner->captureOrNull(
            ['git', '-C', $projectRoot, 'symbolic-ref', '--short', 'HEAD'],
        );

        return $branch ?? '(kein Branch — noch kein Commit)';
    }

    public function isClean(string $projectRoot): bool
    {
        // --porcelain ohne untracked files — nur Änderungen an bereits verfolgten Dateien
        // Untracked files sind für git-first Deployments irrelevant
        $output = $this->runner->capture(
            ['git', '-C', $projectRoot, 'status', '--porcelain', '--untracked-files=no'],
        );

        return '' === $output;
    }

    public function hasRemote(string $projectRoot, string $remote = 'origin'): bool
    {
        $output = $this->runner->captureOrNull(
            ['git', '-C', $projectRoot, 'remote', 'get-url', $remote],
        );

        return null !== $output && '' !== $output;
    }

    public function getRemoteUrl(string $projectRoot, string $remote = 'origin'): ?string
    {
        return $this->runner->captureOrNull(
            ['git', '-C', $projectRoot, 'remote', 'get-url', $remote],
        );
    }

    public function isCommitPushed(string $projectRoot): bool
    {
        $output = $this->runner->captureOrNull(
            ['git', '-C', $projectRoot, 'status', '--short', '--branch'],
        );

        if (null === $output) {
            return false;
        }

        // "[ahead X]" means local commits exist that are not pushed
        return !str_contains($output, '[ahead ');
    }

    public function isBranchAllowed(string $branch, array $allowedBranches): bool
    {
        foreach ($allowedBranches as $pattern) {
            if ($this->matchesBranchPattern($branch, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matchesBranchPattern(string $branch, string $pattern): bool
    {
        if ($pattern === $branch) {
            return true;
        }

        if (str_contains($pattern, '*')) {
            $regex = '#^' . str_replace('\*', '[^/]*', preg_quote($pattern, '#')) . '$#';

            return (bool) preg_match($regex, $branch);
        }

        return false;
    }
}
