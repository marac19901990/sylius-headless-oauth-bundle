<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class SyliusHeadlessOAuthBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
