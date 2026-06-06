<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Tests\Service;

use PHPUnit\Framework\TestCase;
use Revolte\DeployTools\Service\DeployConfigResolver;

class DeployConfigResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/revolte-deploy-config-' . uniqid();
        mkdir($this->tmpDir . '/config', 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/config/*') ?: []);
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir);
    }

    public function testConfigExistsReturnsFalseWhenMissing(): void
    {
        $resolver = new DeployConfigResolver($this->tmpDir);

        $this->assertFalse($resolver->configExists());
    }

    public function testConfigExistsReturnsTrueWhenPresent(): void
    {
        $this->writeConfig(['project' => 'test', 'environments' => []]);
        $resolver = new DeployConfigResolver($this->tmpDir);

        $this->assertTrue($resolver->configExists());
    }

    public function testLoadThrowsWhenConfigMissing(): void
    {
        $resolver = new DeployConfigResolver($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/revolte_deploy\.yaml/');

        $resolver->load();
    }

    public function testLoadReturnsConfigArray(): void
    {
        $this->writeConfig([
            'project' => 'mein-projekt',
            'environments' => ['stage' => ['ssh_profile' => 'mein-projekt-stage']],
        ]);

        $resolver = new DeployConfigResolver($this->tmpDir);
        $config = $resolver->load();

        $this->assertSame('mein-projekt', $config['project']);
    }

    public function testLoadIsCached(): void
    {
        $this->writeConfig(['project' => 'test', 'environments' => []]);
        $resolver = new DeployConfigResolver($this->tmpDir);

        $first = $resolver->load();

        // Overwrite with different content — should still return first load
        $this->writeConfig(['project' => 'changed', 'environments' => []]);

        $second = $resolver->load();

        $this->assertSame($first, $second);
    }

    public function testGetEnvironmentReturnsEnvironment(): void
    {
        $this->writeConfig([
            'project' => 'test',
            'environments' => [
                'stage' => ['ssh_profile' => 'test-stage', 'remote_path' => '/www/stage'],
            ],
        ]);

        $resolver = new DeployConfigResolver($this->tmpDir);
        $env = $resolver->getEnvironment('stage');

        $this->assertSame('test-stage', $env['ssh_profile']);
    }

    public function testGetEnvironmentThrowsForUnknownEnv(): void
    {
        $this->writeConfig(['project' => 'test', 'environments' => ['stage' => []]]);
        $resolver = new DeployConfigResolver($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/live/');

        $resolver->getEnvironment('live');
    }

    public function testGetAvailableEnvironmentsReturnsList(): void
    {
        $this->writeConfig([
            'project' => 'test',
            'environments' => ['stage' => [], 'live' => []],
        ]);

        $resolver = new DeployConfigResolver($this->tmpDir);

        $this->assertSame(['stage', 'live'], $resolver->getAvailableEnvironments());
    }

    public function testGetProjectNameFallsBackToDirectoryName(): void
    {
        $this->writeConfig(['environments' => []]);
        $resolver = new DeployConfigResolver($this->tmpDir);

        // No project key — should return dirname
        $this->assertSame(basename($this->tmpDir), $resolver->getProjectName());
    }

    public function testLoadThrowsOnInvalidYaml(): void
    {
        file_put_contents($this->tmpDir . '/config/revolte_deploy.yaml', "invalid: yaml: :\n  bad indent\nbad:");

        $resolver = new DeployConfigResolver($this->tmpDir);

        $this->expectException(\RuntimeException::class);

        $resolver->load();
    }

    private function writeConfig(array $data): void
    {
        $yaml = '';

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $yaml .= $key . ":\n";
                foreach ($value as $k => $v) {
                    if (is_array($v)) {
                        $yaml .= "  $k:\n";
                        foreach ($v as $kk => $vv) {
                            $yaml .= "    $kk: $vv\n";
                        }
                    } else {
                        $yaml .= "  $k: $v\n";
                    }
                }
            } else {
                $yaml .= "$key: $value\n";
            }
        }

        file_put_contents($this->tmpDir . '/config/revolte_deploy.yaml', $yaml);
    }
}
