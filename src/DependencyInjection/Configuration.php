<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sylius_headless_oauth');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('allowed_redirect_uris')
                            ->info('List of allowed redirect URIs. If empty, all URIs are allowed (not recommended for production).')
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                        ->end()
                        ->booleanNode('verify_apple_jwt')
                            ->info('Whether to verify Apple id_token JWT signatures against Apple JWKS. Disable only for testing.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('providers')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('google')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')
                                    ->defaultTrue()
                                ->end()
                                ->scalarNode('client_id')
                                    ->defaultValue('%env(GOOGLE_CLIENT_ID)%')
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('client_secret')
                                    ->defaultValue('%env(GOOGLE_CLIENT_SECRET)%')
                                    ->cannotBeEmpty()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('apple')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')
                                    ->defaultTrue()
                                ->end()
                                ->scalarNode('client_id')
                                    ->defaultValue('%env(APPLE_CLIENT_ID)%')
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('team_id')
                                    ->defaultValue('%env(APPLE_TEAM_ID)%')
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('key_id')
                                    ->defaultValue('%env(APPLE_KEY_ID)%')
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('private_key_path')
                                    ->defaultValue('%env(APPLE_PRIVATE_KEY_PATH)%')
                                    ->cannotBeEmpty()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('facebook')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')
                                    ->defaultTrue()
                                ->end()
                                ->scalarNode('client_id')
                                    ->defaultValue('%env(FACEBOOK_CLIENT_ID)%')
                                    ->cannotBeEmpty()
                                ->end()
                                ->scalarNode('client_secret')
                                    ->defaultValue('%env(FACEBOOK_CLIENT_SECRET)%')
                                    ->cannotBeEmpty()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
