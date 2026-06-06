<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Tests\Service;

use PHPUnit\Framework\TestCase;
use Revolte\DeployTools\Service\WebDirDetector;

class WebDirDetectorTest extends TestCase
{
    private WebDirDetector $detector;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->detector = new WebDirDetector();
        $this->tmpDir = sys_get_temp_dir() . '/revolte-deploy-webdir-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testDetectsPublicDir(): void
    {
        mkdir($this->tmpDir . '/public');

        $this->assertSame('public', $this->detector->detect($this->tmpDir));
    }

    public function testDetectsWebDir(): void
    {
        mkdir($this->tmpDir . '/web');

        $this->assertSame('web', $this->detector->detect($this->tmpDir));
    }

    public function testPrefersPublicOverWebWhenBothExist(): void
    {
        mkdir($this->tmpDir . '/public');
        mkdir($this->tmpDir . '/web');

        // public/ takes precedence (Contao 5.x standard)
        $this->assertSame('public', $this->detector->detect($this->tmpDir));
    }

    public function testReturnsNullWhenNeitherExists(): void
    {
        $this->assertNull($this->detector->detect($this->tmpDir));
    }

    public function testReadsPublicDirFromComposerJson(): void
    {
        file_put_contents(
            $this->tmpDir . '/composer.json',
            json_encode(['extra' => ['public-dir' => 'public']]),
        );
        mkdir($this->tmpDir . '/public');

        $this->assertSame('public', $this->detector->detect($this->tmpDir));
    }

    public function testReadsCustomDirFromComposerJson(): void
    {
        file_put_contents(
            $this->tmpDir . '/composer.json',
            json_encode(['extra' => ['public-dir' => 'httpdocs']]),
        );
        mkdir($this->tmpDir . '/httpdocs');

        $this->assertSame('httpdocs', $this->detector->detect($this->tmpDir));
    }

    public function testDetectFromComposerJsonReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->detector->detectFromComposerJson($this->tmpDir));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
