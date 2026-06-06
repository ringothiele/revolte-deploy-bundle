<?php

declare(strict_types=1);

namespace Revolte\DeployTools\Provider;

interface ProviderProfileInterface
{
    public function getName(): string;

    /** @return list<string> */
    public function getPhpCandidates(): array;

    /** @return list<string> */
    public function getNotes(): array;
}
