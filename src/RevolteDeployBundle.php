<?php

declare(strict_types=1);

namespace Revolte\DeployTools;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class RevolteDeployBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
