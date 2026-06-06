<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Tests\Service;

use PHPUnit\Framework\TestCase;
use Revolte\DeployTools\Service\ContaoVersionDetector;

class ContaoVersionDetectorTest extends TestCase
{
    private ContaoVersionDetector $detector;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->detector = new ContaoVersionDetector();
        $this->tmpDir = sys_get_temp_dir() . '/revolte-deploy-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        rmdir($this->tmpDir);
    }

    public function testDetectsContao57FromCoreBundleLock(): void
    {
        $this->writeLockFile(['contao/core-bundle' => '5.7.5']);

        $this->assertSame('5.7.5', $this->detector->detect($this->tmpDir));
    }

    public function testDetectsContao413FromCoreBundleLock(): void
    {
        $this->writeLockFile(['contao/core-bundle' => '4.13.12']);

        $this->assertSame('4.13.12', $this->detector->detect($this->tmpDir));
    }

    public function testFallsBackToManagerBundle(): void
    {
        $this->writeLockFile(['contao/manager-bundle' => '5.7.5'], useManagerBundle: true);

        $this->assertSame('5.7.5', $this->detector->detect($this->tmpDir));
    }

    public function testReturnsNullWhenNoLockFile(): void
    {
        $this->assertNull($this->detector->detect($this->tmpDir));
    }

    public function testReturnsNullWhenNoContaoPackage(): void
    {
        $this->writeLockFile(['symfony/console' => '7.4.0']);

        $this->assertNull($this->detector->detect($this->tmpDir));
    }

    public function testGetMajorVersionReturns5(): void
    {
        $this->writeLockFile(['contao/core-bundle' => '5.7.5']);

        $this->assertSame(5, $this->detector->getMajorVersion($this->tmpDir));
    }

    public function testGetMajorVersionReturns4(): void
    {
        $this->writeLockFile(['contao/core-bundle' => '4.13.12']);

        $this->assertSame(4, $this->detector->getMajorVersion($this->tmpDir));
    }

    public function testGetMajorVersionHandlesVPrefix(): void
    {
        $this->writeLockFile(['contao/core-bundle' => 'v5.3.0']);

        $this->assertSame(5, $this->detector->getMajorVersion($this->tmpDir));
    }

    public function testGetMajorVersionReturnsNullWhenNotDetectable(): void
    {
        $this->assertNull($this->detector->getMajorVersion($this->tmpDir));
    }

    private function writeLockFile(array $packages, bool $useManagerBundle = false): void
    {
        $packageList = [];

        foreach ($packages as $name => $version) {
            $packageList[] = ['name' => $name, 'version' => $version];
        }

        $lock = ['packages' => $packageList, 'packages-dev' => []];

        file_put_contents($this->tmpDir . '/composer.lock', json_encode($lock));
    }
}
