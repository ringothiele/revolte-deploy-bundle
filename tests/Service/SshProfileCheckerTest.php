<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Tests\Service;

use PHPUnit\Framework\TestCase;
use Revolte\DeployTools\Service\SshProfileChecker;

class SshProfileCheckerTest extends TestCase
{
    private string $tmpSshConfig;

    protected function setUp(): void
    {
        $this->tmpSshConfig = sys_get_temp_dir() . '/revolte-test-ssh-config-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpSshConfig)) {
            unlink($this->tmpSshConfig);
        }
    }

    public function testProfileExistsReturnsTrueForKnownProfile(): void
    {
        $this->writeSshConfig(<<<SSH
            Host mein-projekt-stage
                HostName 123.456.789.0
                User deploy
                IdentityFile ~/.ssh/revolte/mein_projekt_stage_ed25519

            Host mein-projekt-live
                HostName 123.456.789.1
                User deploy
                IdentityFile ~/.ssh/revolte/mein_projekt_live_ed25519
            SSH);

        $checker = $this->checkerWithConfig($this->tmpSshConfig);

        $this->assertTrue($checker->profileExists('mein-projekt-stage'));
        $this->assertTrue($checker->profileExists('mein-projekt-live'));
    }

    public function testProfileExistsReturnsFalseForUnknownProfile(): void
    {
        $this->writeSshConfig(<<<SSH
            Host mein-projekt-stage
                HostName 123.456.789.0
                User deploy
            SSH);

        $checker = $this->checkerWithConfig($this->tmpSshConfig);

        $this->assertFalse($checker->profileExists('anderes-projekt-stage'));
    }

    public function testProfileExistsReturnsFalseWhenConfigMissing(): void
    {
        $checker = $this->checkerWithConfig('/tmp/nonexistent-ssh-config-file');

        $this->assertFalse($checker->profileExists('irgendwas'));
    }

    public function testGetProfileDetailsReturnsFields(): void
    {
        $this->writeSshConfig(<<<SSH
            Host mein-projekt-stage
                HostName stage.example.de
                User deploy
                Port 22
                IdentityFile ~/.ssh/revolte/mein_projekt_stage_ed25519
                IdentitiesOnly yes
            SSH);

        $checker = $this->checkerWithConfig($this->tmpSshConfig);
        $details = $checker->getProfileDetails('mein-projekt-stage');

        $this->assertIsArray($details);
        $this->assertSame('stage.example.de', $details['hostname']);
        $this->assertSame('deploy', $details['user']);
        $this->assertSame('~/.ssh/revolte/mein_projekt_stage_ed25519', $details['identityfile']);
    }

    public function testGetProfileDetailsReturnsNullForUnknownProfile(): void
    {
        $this->writeSshConfig("Host other\n    HostName example.de\n");

        $checker = $this->checkerWithConfig($this->tmpSshConfig);

        $this->assertNull($checker->getProfileDetails('unknown'));
    }

    public function testGetProfileDetailsDoesNotLeakIntoNextBlock(): void
    {
        $this->writeSshConfig(<<<SSH
            Host first
                HostName first.example.de
                User user1

            Host second
                HostName second.example.de
                User user2
            SSH);

        $checker = $this->checkerWithConfig($this->tmpSshConfig);
        $details = $checker->getProfileDetails('first');

        $this->assertSame('user1', $details['user'] ?? null);
        $this->assertArrayNotHasKey('second', (array) $details);
    }

    public function testBuildRecommendedSnippetContainsProfileName(): void
    {
        $checker = $this->checkerWithConfig('/tmp/unused');
        $snippet = $checker->buildRecommendedSnippet('kundea-stage', 'kundea', 'stage');

        $this->assertStringContainsString('Host kundea-stage', $snippet);
        $this->assertStringContainsString('kundea_stage_ed25519', $snippet);
        $this->assertStringContainsString('IdentitiesOnly yes', $snippet);
        $this->assertStringContainsString('~/.ssh/revolte/', $snippet);
    }

    private function writeSshConfig(string $content): void
    {
        // Remove leading spaces from heredoc indentation
        $lines = explode("\n", $content);
        $normalized = array_map(fn (string $l) => ltrim($l), $lines);
        file_put_contents($this->tmpSshConfig, implode("\n", $normalized));
    }

    private function checkerWithConfig(string $configPath): SshProfileChecker
    {
        return new class($configPath) extends SshProfileChecker {
            public function __construct(private readonly string $testConfigPath) {}

            public function getSshConfigPath(): string
            {
                return $this->testConfigPath;
            }

            public function sshConfigExists(): bool
            {
                return is_file($this->testConfigPath);
            }
        };
    }
}
