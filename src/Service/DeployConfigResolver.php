<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Service;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class DeployConfigResolver
{
    private ?array $config = null;

    public function __construct(private readonly string $projectRoot) {}

    public function getConfigPath(): string
    {
        return $this->projectRoot . '/config/revolte_deploy.yaml';
    }

    public function configExists(): bool
    {
        return is_file($this->getConfigPath());
    }

    public function load(): array
    {
        if (null !== $this->config) {
            return $this->config;
        }

        $path = $this->getConfigPath();

        if (!is_file($path)) {
            throw new \RuntimeException(sprintf(
                'Keine Deploy-Konfiguration gefunden.%sErwarteter Pfad: %s%sVorlage: vendor/revolte/contao-deploy-tools/resources/revolte_deploy.yaml.dist',
                PHP_EOL,
                $path,
                PHP_EOL,
            ));
        }

        try {
            $raw = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new \RuntimeException(sprintf('Fehler beim Lesen der Konfiguration: %s', $e->getMessage()));
        }

        if (!is_array($raw) || empty($raw)) {
            throw new \RuntimeException('Die Deploy-Konfiguration ist leer oder ungültig.');
        }

        $this->assertNoPlaceholders($raw, '');

        $this->config = $raw;

        return $this->config;
    }

    /**
     * Rejects values that still contain unreplaced placeholders like <organisation>.
     * Copy-pasted templates otherwise fail much later with cryptic errors
     * (e.g. git clone: "is not a valid repository name").
     */
    private function assertNoPlaceholders(array $config, string $path): void
    {
        foreach ($config as $key => $value) {
            $current = '' === $path ? (string) $key : $path . '.' . $key;

            if (is_array($value)) {
                $this->assertNoPlaceholders($value, $current);
                continue;
            }

            if (is_string($value) && preg_match('/<[^<>\s][^<>]*>/', $value)) {
                throw new \RuntimeException(sprintf(
                    'Platzhalter nicht ersetzt: "%s" (unter "%s").%sBitte den echten Wert in config/revolte_deploy.yaml eintragen.',
                    $value,
                    $current,
                    PHP_EOL,
                ));
            }
        }
    }

    public function getEnvironment(string $name): array
    {
        $config = $this->load();

        if (!isset($config['environments'][$name])) {
            $available = array_keys($config['environments'] ?? []);
            throw new \RuntimeException(sprintf(
                'Umgebung "%s" nicht gefunden.%sVerfügbar: %s',
                $name,
                PHP_EOL,
                $available ? implode(', ', $available) : '(keine konfiguriert)',
            ));
        }

        return $config['environments'][$name];
    }

    public function getProfile(string $name): array
    {
        $config = $this->load();

        if (!isset($config['profiles'][$name])) {
            $available = array_keys($config['profiles'] ?? []);
            throw new \RuntimeException(sprintf(
                'Profil "%s" nicht gefunden.%sVerfügbar: %s',
                $name,
                PHP_EOL,
                $available ? implode(', ', $available) : '(keine konfiguriert)',
            ));
        }

        return $config['profiles'][$name];
    }

    public function getAvailableEnvironments(): array
    {
        $config = $this->load();

        return array_keys($config['environments'] ?? []);
    }

    public function getAvailableProfiles(): array
    {
        $config = $this->load();

        return array_keys($config['profiles'] ?? []);
    }

    public function getProjectName(): string
    {
        $config = $this->load();

        return (string) ($config['project'] ?? basename($this->projectRoot));
    }
}
