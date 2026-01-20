<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\DependencyInjection;

use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class SyliusHeadlessOAuthExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        $container->setParameter('sylius_headless_oauth.providers.google.enabled', $config['providers']['google']['enabled']);
        $container->setParameter('sylius_headless_oauth.providers.google.client_id', $config['providers']['google']['client_id']);
        $container->setParameter('sylius_headless_oauth.providers.google.client_secret', $config['providers']['google']['client_secret']);

        $container->setParameter('sylius_headless_oauth.providers.apple.enabled', $config['providers']['apple']['enabled']);
        $container->setParameter('sylius_headless_oauth.providers.apple.client_id', $config['providers']['apple']['client_id']);
        $container->setParameter('sylius_headless_oauth.providers.apple.team_id', $config['providers']['apple']['team_id']);
        $container->setParameter('sylius_headless_oauth.providers.apple.key_id', $config['providers']['apple']['key_id']);
        $container->setParameter('sylius_headless_oauth.providers.apple.private_key_path', $config['providers']['apple']['private_key_path']);

        $container->registerForAutoconfiguration(OAuthProviderInterface::class)
            ->addTag('sylius_headless_oauth.provider');
    }
}
