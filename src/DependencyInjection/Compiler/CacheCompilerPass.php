<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass to configure cache service based on availability.
 *
 * By default, the bundle uses NullCacheItemPool (defined in services.yaml).
 * This pass upgrades to cache.app when it's available, enabling caching
 * for OIDC discovery in environments with the framework cache enabled.
 */
final class CacheCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Upgrade to cache.app if available
        if ($container->has('cache.app')) {
            $container->setAlias('sylius_headless_oauth.cache', 'cache.app')
                ->setPublic(false);
        }
    }
}
