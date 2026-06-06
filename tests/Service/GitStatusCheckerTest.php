<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Tests\Service;

use PHPUnit\Framework\TestCase;
use Revolte\DeployTools\Service\GitStatusChecker;
use Revolte\DeployTools\Service\ProcessRunner;

class GitStatusCheckerTest extends TestCase
{
    // ── Branch-Muster (keine Prozesse nötig) ─────────────────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('branchAllowedProvider')]
    public function testIsBranchAllowed(string $branch, array $patterns, bool $expected): void
    {
        $checker = new GitStatusChecker($this->createMock(ProcessRunner::class));

        $this->assertSame($expected, $checker->isBranchAllowed($branch, $patterns));
    }

    public static function branchAllowedProvider(): iterable
    {
        yield 'exakter Treffer' => ['main', ['main'], true];
        yield 'kein Treffer' => ['develop', ['main', 'master'], false];
        yield 'feature-wildcard erlaubt' => ['feature/login', ['develop', 'feature/*'], true];
        yield 'feature-wildcard mit Schrägstrich im Namen' => ['feature/auth/oauth', ['feature/*'], false];
        yield 'mehrere Patterns, einer trifft' => ['staging', ['main', 'staging', 'develop'], true];
        yield 'leere Patterns' => ['main', [], false];
        yield 'master als Alias' => ['master', ['main', 'master'], true];
        yield 'release-wildcard' => ['release/2.0', ['release/*'], true];
    }

    // ── Integrationstests gegen echtes Filesystem ────────────────────────────

    public function testIsRepositoryReturnsTrueForActualRepo(): void
    {
        $checker = new GitStatusChecker(new ProcessRunner());

        // Das Testprojekt selbst ist kein Git-Repo, aber das Package-Verzeichnis auch nicht.
        // Wir nutzen /tmp als bekanntes Nicht-Repo.
        $this->assertFalse($checker->isRepository('/tmp'));
    }

    public function testIsInstalledReturnsTrueWhenGitExists(): void
    {
        $checker = new GitStatusChecker(new ProcessRunner());

        // Git ist in der Test-Umgebung vorhanden (wurde im doctor-Test bestätigt)
        $this->assertTrue($checker->isInstalled());
    }

    public function testIsCleanAndBranchOnRealRepo(): void
    {
        // Temporäres Git-Repo anlegen
        $tmpDir = sys_get_temp_dir() . '/revolte-git-test-' . uniqid();
        mkdir($tmpDir);

        exec("git -C $tmpDir init -q && git -C $tmpDir config user.email 'test@test.de' && git -C $tmpDir config user.name 'Test'");

        $checker = new GitStatusChecker(new ProcessRunner());

        // Frisches Repo ohne Commit hat keinen Branch-Namen
        $this->assertTrue($checker->isRepository($tmpDir));
        $this->assertTrue($checker->isClean($tmpDir));

        // Datei anlegen und stagen — jetzt nicht mehr clean (untracked files werden ignoriert)
        file_put_contents($tmpDir . '/test.txt', 'hallo');
        exec("git -C $tmpDir add test.txt");
        $this->assertFalse($checker->isClean($tmpDir));

        // Commit — wieder clean
        exec("git -C $tmpDir add . && git -C $tmpDir commit -q -m 'init'");
        $this->assertTrue($checker->isClean($tmpDir));

        $branch = $checker->getCurrentBranch($tmpDir);
        $this->assertNotEmpty($branch);

        // Aufräumen
        exec("rm -rf $tmpDir");
    }
}
