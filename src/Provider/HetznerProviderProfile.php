<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Provider;

class HetznerProviderProfile implements ProviderProfileInterface
{
    public function getName(): string
    {
        return 'hetzner';
    }

    public function getPhpCandidates(): array
    {
        return [
            '/usr/bin/php8.4',
            '/usr/bin/php84',
            '/usr/bin/php8.3',
            '/usr/bin/php83',
            '/usr/bin/php8.2',
            '/usr/bin/php82',
            '/usr/bin/php8.1',
            '/usr/bin/php81',
            'php',
        ];
    }

    public function getNotes(): array
    {
        return [
            'Hetzner Managed Server drosselt SSH-Verbindungen unter starker Last.',
            'Git-first Deployment (statt rsync) ist für Hetzner zwingend empfohlen.',
            'GitHub Deploy Key mit Leserechten muss auf dem Zielserver eingerichtet sein.',
        ];
    }
}
