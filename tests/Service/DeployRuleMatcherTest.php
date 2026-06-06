<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Tests\Service;

use PHPUnit\Framework\TestCase;
use Revolte\DeployTools\Service\DeployRuleMatcher;

class DeployRuleMatcherTest extends TestCase
{
    private DeployRuleMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new DeployRuleMatcher();
    }

    // ── Code-Profil (default: disallow) ──────────────────────────────────────

    public function testCodeProfileAllowsTemplates(): void
    {
        $this->assertTrue($this->matcher->match('/templates/layout.html.twig', $this->codeProfile()));
    }

    public function testCodeProfileAllowsSrcFiles(): void
    {
        $this->assertTrue($this->matcher->match('/src/Controller/DefaultController.php', $this->codeProfile()));
    }

    public function testCodeProfileAllowsComposerJson(): void
    {
        $this->assertTrue($this->matcher->match('/composer.json', $this->codeProfile()));
    }

    public function testCodeProfileAllowsComposerLock(): void
    {
        $this->assertTrue($this->matcher->match('/composer.lock', $this->codeProfile()));
    }

    public function testCodeProfileAllowsPublicBundles(): void
    {
        $this->assertTrue($this->matcher->match('/public/bundles/contaocore/css/main.css', $this->codeProfile()));
    }

    public function testCodeProfileAllowsLayoutFiles(): void
    {
        $this->assertTrue($this->matcher->match('/files/meinprojekt/layout/main.css', $this->codeProfile()));
    }

    public function testCodeProfileDeniesVendor(): void
    {
        $this->assertFalse($this->matcher->match('/vendor/symfony/console/Command.php', $this->codeProfile()));
    }

    public function testCodeProfileDeniesVar(): void
    {
        $this->assertFalse($this->matcher->match('/var/cache/prod/App_KernelProdContainer.php', $this->codeProfile()));
    }

    public function testCodeProfileDeniesEnvLocal(): void
    {
        $this->assertFalse($this->matcher->match('/.env.local', $this->codeProfile()));
    }

    public function testCodeProfileDeniesEnvFile(): void
    {
        $this->assertFalse($this->matcher->match('/.env', $this->codeProfile()));
    }

    public function testCodeProfileDeniesContentImages(): void
    {
        $this->assertFalse($this->matcher->match('/files/meinprojekt/content/team.jpg', $this->codeProfile()));
    }

    public function testCodeProfileDeniesDownloads(): void
    {
        $this->assertFalse($this->matcher->match('/files/meinprojekt/downloads/broschüre.pdf', $this->codeProfile()));
    }

    public function testCodeProfileDeniesNodeModules(): void
    {
        $this->assertFalse($this->matcher->match('/node_modules/lodash/index.js', $this->codeProfile()));
    }

    public function testCodeProfileDeniesGitDir(): void
    {
        $this->assertFalse($this->matcher->match('/.git/config', $this->codeProfile()));
    }

    public function testCodeProfileDeniesUnknownRootFile(): void
    {
        // default: disallow — unbekannte Dateien werden nicht deployed
        $this->assertFalse($this->matcher->match('/some-random-file.txt', $this->codeProfile()));
    }

    // ── Full-Profil (default: allow) ─────────────────────────────────────────

    public function testFullProfileAllowsAnythingByDefault(): void
    {
        $this->assertTrue($this->matcher->match('/src/Controller/Foo.php', $this->fullProfile()));
    }

    public function testFullProfileDeniesGitDir(): void
    {
        $this->assertFalse($this->matcher->match('/.git/config', $this->fullProfile()));
    }

    public function testFullProfileDeniesEnvLocal(): void
    {
        $this->assertFalse($this->matcher->match('/.env.local', $this->fullProfile()));
    }

    public function testFullProfileDeniesVarCache(): void
    {
        $this->assertFalse($this->matcher->match('/var/cache/prod/container.php', $this->fullProfile()));
    }

    public function testFullProfileDeniesNodeModules(): void
    {
        $this->assertFalse($this->matcher->match('/node_modules/react/index.js', $this->fullProfile()));
    }

    public function testFullProfileAllowsFilesContent(): void
    {
        // Full-Profil schließt Content NICHT aus — das ist Code-Profil-Logik
        $this->assertTrue($this->matcher->match('/files/meinprojekt/content/team.jpg', $this->fullProfile()));
    }

    // ── Wildcard-Muster ──────────────────────────────────────────────────────

    public function testDoubleStarMatchesNestedPaths(): void
    {
        $profile = ['default' => 'disallow', 'rules' => [['allow' => '/src/**']]];

        $this->assertTrue($this->matcher->match('/src/a/b/c/deep.php', $profile));
        $this->assertTrue($this->matcher->match('/src/file.php', $profile));
    }

    public function testSingleStarDoesNotMatchSlash(): void
    {
        $profile = ['default' => 'disallow', 'rules' => [['allow' => '/files/*/layout/**']]];

        $this->assertTrue($this->matcher->match('/files/projekt/layout/main.css', $profile));
        $this->assertFalse($this->matcher->match('/files/a/b/layout/main.css', $profile)); // * darf kein / matchen
    }

    public function testLastRuleWins(): void
    {
        $profile = [
            'default' => 'disallow',
            'rules' => [
                ['allow' => '/config/**'],
                ['disallow' => '/config/secrets/**'],
            ],
        ];

        $this->assertTrue($this->matcher->match('/config/services.yaml', $profile));
        $this->assertFalse($this->matcher->match('/config/secrets/keys.yaml', $profile));
    }

    // ── Explain-Ausgabe ──────────────────────────────────────────────────────

    public function testExplainShowsActiveRule(): void
    {
        $result = $this->matcher->explain('/templates/layout.html.twig', $this->codeProfile());

        $this->assertTrue($result['allowed']);

        $activeRules = array_filter($result['trace'], fn (array $e) => $e['active']);
        $this->assertCount(1, $activeRules);

        $activeRule = array_values($activeRules)[0];
        $this->assertStringContainsString('/templates/**', $activeRule['rule']);
    }

    public function testExplainShowsDefaultAsActiveWhenNoRuleMatches(): void
    {
        $profile = ['default' => 'disallow', 'rules' => [['allow' => '/src/**']]];
        $result = $this->matcher->explain('/random/file.txt', $profile);

        $this->assertFalse($result['allowed']);

        $activeRules = array_filter($result['trace'], fn (array $e) => $e['active']);
        $this->assertCount(1, $activeRules);
        $this->assertStringContainsString('default', array_values($activeRules)[0]['rule']);
    }

    // ── Hilfsmethoden ────────────────────────────────────────────────────────

    private function codeProfile(): array
    {
        return [
            'default' => 'disallow',
            'rules' => [
                ['allow' => '/src/**'],
                ['allow' => '/contao/**'],
                ['allow' => '/config/**'],
                ['allow' => '/templates/**'],
                ['allow' => '/assets/**'],
                ['allow' => '/composer.json'],
                ['allow' => '/composer.lock'],
                ['allow' => '/public/bundles/**'],
                ['allow' => '/web/bundles/**'],
                ['allow' => '/files/*/layout/**'],
                ['allow' => '/files/*/theme/**'],
                ['allow' => '/files/*/css/**'],
                ['allow' => '/files/*/js/**'],
                ['disallow' => '/.env*'],
                ['disallow' => '/var/**'],
                ['disallow' => '/vendor/**'],
                ['disallow' => '/node_modules/**'],
                ['disallow' => '/.git/**'],
                ['disallow' => '/.ddev/**'],
                ['disallow' => '/files/*/content/**'],
                ['disallow' => '/files/*/downloads/**'],
                ['disallow' => '/files/*/user_upload/**'],
            ],
        ];
    }

    private function fullProfile(): array
    {
        return [
            'default' => 'allow',
            'rules' => [
                ['disallow' => '/.git/**'],
                ['disallow' => '/.ddev/**'],
                ['disallow' => '/.env.local'],
                ['disallow' => '/var/cache/**'],
                ['disallow' => '/var/log/**'],
                ['disallow' => '/node_modules/**'],
                ['disallow' => '/backups/**'],
            ],
        ];
    }
}
