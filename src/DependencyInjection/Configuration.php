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
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
