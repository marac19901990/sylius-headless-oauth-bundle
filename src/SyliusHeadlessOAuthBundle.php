<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle;

use Marac\SyliusHeadlessOAuthBundle\DependencyInjection\Compiler\CacheCompilerPass;
use Marac\SyliusHeadlessOAuthBundle\DependencyInjection\Compiler\LoggerCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

use function dirname;

final class SyliusHeadlessOAuthBundle extends Bundle
{
    public function getPath(): string
    {
        return dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new LoggerCompilerPass());
        $container->addCompilerPass(new CacheCompilerPass());
    }
}
