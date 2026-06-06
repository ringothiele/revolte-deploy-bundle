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

        $this->config = $raw;

        return $this->config;
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
