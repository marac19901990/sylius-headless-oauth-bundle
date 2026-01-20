<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\DependencyInjection;

use Marac\SyliusHeadlessOAuthBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    private Processor $processor;
    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->processor = new Processor();
        $this->configuration = new Configuration();
    }

    #[Test]
    public function defaultConfigurationIsValid(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, []);

        self::assertArrayHasKey('providers', $config);
        self::assertArrayHasKey('google', $config['providers']);
        self::assertArrayHasKey('apple', $config['providers']);
    }

    #[Test]
    public function googleProviderHasDefaultValues(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, []);

        self::assertTrue($config['providers']['google']['enabled']);
        self::assertSame('%env(GOOGLE_CLIENT_ID)%', $config['providers']['google']['client_id']);
        self::assertSame('%env(GOOGLE_CLIENT_SECRET)%', $config['providers']['google']['client_secret']);
    }

    #[Test]
    public function appleProviderHasDefaultValues(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, []);

        self::assertTrue($config['providers']['apple']['enabled']);
        self::assertSame('%env(APPLE_CLIENT_ID)%', $config['providers']['apple']['client_id']);
        self::assertSame('%env(APPLE_TEAM_ID)%', $config['providers']['apple']['team_id']);
        self::assertSame('%env(APPLE_KEY_ID)%', $config['providers']['apple']['key_id']);
        self::assertSame('%env(APPLE_PRIVATE_KEY_PATH)%', $config['providers']['apple']['private_key_path']);
    }

    #[Test]
    public function canDisableGoogleProvider(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'providers' => [
                    'google' => ['enabled' => false],
                ],
            ],
        ]);

        self::assertFalse($config['providers']['google']['enabled']);
    }

    #[Test]
    public function canDisableAppleProvider(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'providers' => [
                    'apple' => ['enabled' => false],
                ],
            ],
        ]);

        self::assertFalse($config['providers']['apple']['enabled']);
    }

    #[Test]
    public function canOverrideGoogleClientId(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'providers' => [
                    'google' => ['client_id' => 'custom-client-id'],
                ],
            ],
        ]);

        self::assertSame('custom-client-id', $config['providers']['google']['client_id']);
    }

    #[Test]
    public function canOverrideGoogleClientSecret(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'providers' => [
                    'google' => ['client_secret' => 'custom-client-secret'],
                ],
            ],
        ]);

        self::assertSame('custom-client-secret', $config['providers']['google']['client_secret']);
    }

    #[Test]
    public function canOverrideAppleConfiguration(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'providers' => [
                    'apple' => [
                        'client_id' => 'custom-apple-client-id',
                        'team_id' => 'custom-team-id',
                        'key_id' => 'custom-key-id',
                        'private_key_path' => '/path/to/key.p8',
                    ],
                ],
            ],
        ]);

        self::assertSame('custom-apple-client-id', $config['providers']['apple']['client_id']);
        self::assertSame('custom-team-id', $config['providers']['apple']['team_id']);
        self::assertSame('custom-key-id', $config['providers']['apple']['key_id']);
        self::assertSame('/path/to/key.p8', $config['providers']['apple']['private_key_path']);
    }

    #[Test]
    public function multipleConfigurationsAreMerged(): void
    {
        $config = $this->processor->processConfiguration($this->configuration, [
            [
                'providers' => [
                    'google' => ['client_id' => 'first-client-id'],
                ],
            ],
            [
                'providers' => [
                    'google' => ['client_secret' => 'second-client-secret'],
                ],
            ],
        ]);

        self::assertSame('first-client-id', $config['providers']['google']['client_id']);
        self::assertSame('second-client-secret', $config['providers']['google']['client_secret']);
    }

    #[Test]
    public function treeBuilderHasCorrectName(): void
    {
        $treeBuilder = $this->configuration->getConfigTreeBuilder();

        self::assertSame('sylius_headless_oauth', $treeBuilder->buildTree()->getName());
    }
}
