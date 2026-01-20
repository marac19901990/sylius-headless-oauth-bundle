<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\DependencyInjection\Compiler;

use Marac\SyliusHeadlessOAuthBundle\Security\OAuthSecurityLogger;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Compiler pass to configure logger fallback for minimal kernels.
 *
 * If the logger service is not available (no Monolog installed),
 * this pass configures the OAuthSecurityLogger to use NullLogger.
 */
final class LoggerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(OAuthSecurityLogger::class)) {
            return;
        }

        $definition = $container->getDefinition(OAuthSecurityLogger::class);

        // If logger service doesn't exist, use NullLogger
        if (!$container->has('logger')) {
            $definition->setArgument('$logger', new Reference(NullLogger::class));
        }
    }
}
