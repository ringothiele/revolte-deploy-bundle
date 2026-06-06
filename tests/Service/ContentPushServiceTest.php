<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Tests\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Revolte\DeployTools\Service\ContentPushService;
use Revolte\DeployTools\Service\DatabaseSyncService;

class ContentPushServiceTest extends TestCase
{
    private string $tmpDir;
    private ContentPushService $service;
    private DatabaseSyncService&MockObject $dbSync;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/revolte-push-' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->dbSync = $this->createMock(DatabaseSyncService::class);
        $this->service = new ContentPushService($this->dbSync);
    }

    protected function tearDown(): void
    {
        $files = array_filter(
            glob($this->tmpDir . '/{,.}*', \GLOB_BRACE) ?: [],
            fn ($f) => is_file($f),
        );
        array_map('unlink', $files);
        rmdir($this->tmpDir);
    }

    // ── readBaseline ──────────────────────────────────────────────────────────

    public function testReadBaselineThrowsWhenFileMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Keine Baseline gefunden');

        $this->service->readBaseline($this->tmpDir);
    }

    public function testReadBaselineThrowsOnInvalidJson(): void
    {
        file_put_contents($this->tmpDir . '/.revolte-content-baseline.json', '{"broken":true}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ungültig');

        $this->service->readBaseline($this->tmpDir);
    }

    public function testReadBaselineReturnsData(): void
    {
        $data = ['pulled_at' => 1000, 'environment' => 'live', 'max_ids' => ['tl_page' => 10]];
        file_put_contents($this->tmpDir . '/.revolte-content-baseline.json', json_encode($data));

        $result = $this->service->readBaseline($this->tmpDir);

        $this->assertSame(1000, $result['pulled_at']);
        $this->assertSame('live', $result['environment']);
        $this->assertSame(10, $result['max_ids']['tl_page']);
    }

    // ── buildIdMap ────────────────────────────────────────────────────────────

    public function testBuildIdMapAssignsSequentialRemoteIds(): void
    {
        $newRecords = [
            'tl_page' => [
                ['id' => '101', 'title' => 'Neue Seite'],
                ['id' => '102', 'title' => 'Noch eine Seite'],
            ],
            'tl_article' => [
                ['id' => '201', 'title' => 'Artikel'],
            ],
        ];

        $remoteMaxIds = ['tl_page' => 50, 'tl_article' => 100, 'tl_content' => 0, 'tl_layout' => 0, 'tl_module' => 0];

        $idMap = $this->service->buildIdMap($newRecords, $remoteMaxIds);

        $this->assertSame([101 => 51, 102 => 52], $idMap['tl_page']);
        $this->assertSame([201 => 101], $idMap['tl_article']);
        $this->assertArrayNotHasKey('tl_content', $idMap);
    }

    public function testBuildIdMapWithZeroRemoteMax(): void
    {
        $newRecords = ['tl_layout' => [['id' => '1', 'name' => 'Default']]];
        $remoteMaxIds = array_fill_keys(ContentPushService::TABLES, 0);

        $idMap = $this->service->buildIdMap($newRecords, $remoteMaxIds);

        $this->assertSame([1 => 1], $idMap['tl_layout']);
    }

    // ── remapRecords ──────────────────────────────────────────────────────────

    public function testRemapRecordsUpdatesOwnId(): void
    {
        $newRecords = ['tl_layout' => [['id' => '5', 'name' => 'Test']]];
        $idMap = ['tl_layout' => [5 => 99]];

        $result = $this->service->remapRecords($newRecords, $idMap);

        $this->assertSame('99', $result['tl_layout'][0]['id']);
    }

    public function testRemapRecordsSetsPageUnpublished(): void
    {
        $newRecords = ['tl_page' => [['id' => '10', 'title' => 'Seite', 'published' => '1', 'pid' => '0', 'jumpTo' => '0']]];
        $idMap = ['tl_page' => [10 => 51]];

        $result = $this->service->remapRecords($newRecords, $idMap);

        $this->assertSame('', $result['tl_page'][0]['published']);
    }

    public function testRemapRecordsRemapsSelfReferencingPagePid(): void
    {
        $newRecords = [
            'tl_page' => [
                ['id' => '10', 'title' => 'Parent', 'published' => '1', 'pid' => '0', 'jumpTo' => '0'],
                ['id' => '11', 'title' => 'Child', 'published' => '1', 'pid' => '10', 'jumpTo' => '0'],
            ],
        ];
        $idMap = ['tl_page' => [10 => 51, 11 => 52]];

        $result = $this->service->remapRecords($newRecords, $idMap);

        $this->assertSame('51', $result['tl_page'][0]['id']);
        $this->assertSame('52', $result['tl_page'][1]['id']);
        $this->assertSame('51', $result['tl_page'][1]['pid']); // remapped
    }

    public function testRemapRecordsRemapsArticlePidToPage(): void
    {
        $newRecords = [
            'tl_article' => [['id' => '200', 'pid' => '10', 'title' => 'Artikel']],
        ];
        $idMap = [
            'tl_page' => [10 => 51],
            'tl_article' => [200 => 101],
        ];

        $result = $this->service->remapRecords($newRecords, $idMap);

        $this->assertSame('101', $result['tl_article'][0]['id']);
        $this->assertSame('51', $result['tl_article'][0]['pid']);
    }

    public function testRemapRecordsRemapsContentFkColumns(): void
    {
        $newRecords = [
            'tl_content' => [[
                'id' => '300',
                'pid' => '200',       // → tl_article
                'cteAlias' => '301',  // → tl_content (self)
                'articleAlias' => '0',
                'module' => '50',     // → tl_module
                'jumpTo' => '10',     // → tl_page
            ]],
        ];
        $idMap = [
            'tl_page' => [10 => 51],
            'tl_article' => [200 => 101],
            'tl_content' => [300 => 400, 301 => 401],
            'tl_module' => [50 => 60],
        ];

        $result = $this->service->remapRecords($newRecords, $idMap);
        $row = $result['tl_content'][0];

        $this->assertSame('400', $row['id']);
        $this->assertSame('101', $row['pid']);
        $this->assertSame('401', $row['cteAlias']);
        $this->assertSame('0', $row['articleAlias']); // '0' is skipped
        $this->assertSame('60', $row['module']);
        $this->assertSame('51', $row['jumpTo']);
    }

    public function testRemapRecordsLeavesExistingFkReferencesUnchanged(): void
    {
        // pid=5 is NOT in idMap (it's an existing page), so it stays as-is
        $newRecords = [
            'tl_article' => [['id' => '200', 'pid' => '5', 'title' => 'Artikel']],
        ];
        $idMap = [
            'tl_page' => [10 => 51],   // only local id=10 is new
            'tl_article' => [200 => 101],
        ];

        $result = $this->service->remapRecords($newRecords, $idMap);

        $this->assertSame('5', $result['tl_article'][0]['pid']); // untouched
    }

    public function testRemapRecordsHandlesNullFkColumn(): void
    {
        $newRecords = [
            'tl_content' => [[
                'id' => '300',
                'pid' => '200',
                'cteAlias' => null,
                'articleAlias' => null,
                'module' => null,
                'jumpTo' => null,
            ]],
        ];
        $idMap = [
            'tl_article' => [200 => 101],
            'tl_content' => [300 => 400],
            'tl_page' => [],
            'tl_module' => [],
        ];

        $result = $this->service->remapRecords($newRecords, $idMap);
        $row = $result['tl_content'][0];

        $this->assertNull($row['cteAlias']);
        $this->assertNull($row['module']);
        $this->assertNull($row['jumpTo']);
    }

    // ── validatePrePush ───────────────────────────────────────────────────────

    public function testValidatePrePushReturnsEmptyWhenNoModifiedRecords(): void
    {
        $baseline = ['pulled_at' => 1000, 'max_ids' => ['tl_page' => 10, 'tl_article' => 0, 'tl_content' => 0, 'tl_layout' => 0, 'tl_module' => 0]];

        $this->dbSync
            ->expects($this->once())
            ->method('runLocalQueryScalar')
            ->willReturn('0');

        $warnings = $this->service->validatePrePush($this->tmpDir, $baseline);

        $this->assertSame([], $warnings);
    }

    public function testValidatePrePushReturnsWarningForModifiedRecords(): void
    {
        $baseline = ['pulled_at' => 1000, 'max_ids' => ['tl_page' => 10, 'tl_article' => 0, 'tl_content' => 0, 'tl_layout' => 0, 'tl_module' => 0]];

        $this->dbSync
            ->expects($this->once())
            ->method('runLocalQueryScalar')
            ->willReturn('3');

        $warnings = $this->service->validatePrePush($this->tmpDir, $baseline);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('tl_page', $warnings[0]);
        $this->assertStringContainsString('3', $warnings[0]);
    }
}
