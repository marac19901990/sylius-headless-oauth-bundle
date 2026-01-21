<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\DependencyInjection;

use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use Marac\SyliusHeadlessOAuthBundle\Provider\OpenIdConnectProvider;
use Marac\SyliusHeadlessOAuthBundle\Service\OidcDiscoveryService;
use Marac\SyliusHeadlessOAuthBundle\Validator\CredentialValidatorInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

final class SyliusHeadlessOAuthExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependTwigConfig($container);
        $this->prependDoctrineConfig($container);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        // Security settings
        $container->setParameter('sylius_headless_oauth.security.allowed_redirect_uris', $config['security']['allowed_redirect_uris']);
        $container->setParameter('sylius_headless_oauth.security.verify_apple_jwt', $config['security']['verify_apple_jwt']);

        // Provider settings
        $container->setParameter('sylius_headless_oauth.providers.google.enabled', $config['providers']['google']['enabled']);
        $container->setParameter('sylius_headless_oauth.providers.google.client_id', $config['providers']['google']['client_id']);
        $container->setParameter('sylius_headless_oauth.providers.google.client_secret', $config['providers']['google']['client_secret']);

        $container->setParameter('sylius_headless_oauth.providers.apple.enabled', $config['providers']['apple']['enabled']);
        $container->setParameter('sylius_headless_oauth.providers.apple.client_id', $config['providers']['apple']['client_id']);
        $container->setParameter('sylius_headless_oauth.providers.apple.team_id', $config['providers']['apple']['team_id']);
        $container->setParameter('sylius_headless_oauth.providers.apple.key_id', $config['providers']['apple']['key_id']);
        $container->setParameter('sylius_headless_oauth.providers.apple.private_key_path', $config['providers']['apple']['private_key_path']);

        $container->setParameter('sylius_headless_oauth.providers.facebook.enabled', $config['providers']['facebook']['enabled']);
        $container->setParameter('sylius_headless_oauth.providers.facebook.client_id', $config['providers']['facebook']['client_id']);
        $container->setParameter('sylius_headless_oauth.providers.facebook.client_secret', $config['providers']['facebook']['client_secret']);

        $container->setParameter('sylius_headless_oauth.providers.github.enabled', $config['providers']['github']['enabled']);
        $container->setParameter('sylius_headless_oauth.providers.github.client_id', $config['providers']['github']['client_id']);
        $container->setParameter('sylius_headless_oauth.providers.github.client_secret', $config['providers']['github']['client_secret']);

        $container->setParameter('sylius_headless_oauth.providers.linkedin.enabled', $config['providers']['linkedin']['enabled']);
        $container->setParameter('sylius_headless_oauth.providers.linkedin.client_id', $config['providers']['linkedin']['client_id']);
        $container->setParameter('sylius_headless_oauth.providers.linkedin.client_secret', $config['providers']['linkedin']['client_secret']);

        // Register OIDC providers
        $this->registerOidcProviders($container, $config['providers']['oidc'] ?? []);

        $container->registerForAutoconfiguration(OAuthProviderInterface::class)
            ->addTag('sylius_headless_oauth.provider');
    }

    /**
     * @param array<string, array{enabled: bool, issuer_url: string, client_id: string, client_secret: string, verify_jwt: bool, scopes: string, user_identifier_field: string}> $oidcProviders
     */
    private function registerOidcProviders(ContainerBuilder $container, array $oidcProviders): void
    {
        // Store OIDC provider configurations for later use
        $container->setParameter('sylius_headless_oauth.providers.oidc', $oidcProviders);

        foreach ($oidcProviders as $name => $providerConfig) {
            $serviceId = 'sylius_headless_oauth.provider.oidc.' . $name;

            $definition = new Definition(OpenIdConnectProvider::class);
            $definition->setArguments([
                new Reference('Marac\SyliusHeadlessOAuthBundle\Http\OAuthHttpClient'),
                new Reference(OidcDiscoveryService::class),
                new Reference(CredentialValidatorInterface::class),
                $providerConfig['client_id'],
                $providerConfig['client_secret'],
                $providerConfig['issuer_url'],
                $providerConfig['enabled'],
                $providerConfig['verify_jwt'],
                $name,
                $providerConfig['scopes'],
            ]);
            $definition->addTag('sylius_headless_oauth.provider');

            $container->setDefinition($serviceId, $definition);
        }
    }

    private function prependTwigConfig(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('twig')) {
            return;
        }

        $container->prependExtensionConfig('twig', [
            'paths' => [
                __DIR__ . '/../../templates' => 'SyliusHeadlessOAuth',
            ],
        ]);
    }

    private function prependDoctrineConfig(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'SyliusHeadlessOAuthBundle' => [
                        'type' => 'xml',
                        'dir' => __DIR__ . '/../../config/doctrine',
                        'prefix' => 'Marac\SyliusHeadlessOAuthBundle\Entity',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);
    }
}
