<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\DependencyInjection;

use Marac\SyliusHeadlessOAuthBundle\DependencyInjection\SyliusHeadlessOAuthExtension;
use Marac\SyliusHeadlessOAuthBundle\Provider\OAuthProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(SyliusHeadlessOAuthExtension::class)]
final class ExtensionTest extends TestCase
{
    private ContainerBuilder $container;
    private SyliusHeadlessOAuthExtension $extension;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->extension = new SyliusHeadlessOAuthExtension();
    }

    #[Test]
    public function loadsServicesConfiguration(): void
    {
        $this->extension->load([], $this->container);

        // Check that services are registered
        self::assertTrue($this->container->has('Marac\SyliusHeadlessOAuthBundle\Provider\GoogleProvider'));
        self::assertTrue($this->container->has('Marac\SyliusHeadlessOAuthBundle\Provider\AppleProvider'));
        self::assertTrue($this->container->has('Marac\SyliusHeadlessOAuthBundle\Processor\OAuthProcessor'));
        self::assertTrue($this->container->has('Marac\SyliusHeadlessOAuthBundle\Processor\OAuthRefreshProcessor'));
    }

    #[Test]
    public function setsGoogleParameters(): void
    {
        $this->extension->load([], $this->container);

        self::assertTrue($this->container->getParameter('sylius_headless_oauth.providers.google.enabled'));
        self::assertSame('%env(GOOGLE_CLIENT_ID)%', $this->container->getParameter('sylius_headless_oauth.providers.google.client_id'));
        self::assertSame('%env(GOOGLE_CLIENT_SECRET)%', $this->container->getParameter('sylius_headless_oauth.providers.google.client_secret'));
    }

    #[Test]
    public function setsAppleParameters(): void
    {
        $this->extension->load([], $this->container);

        self::assertTrue($this->container->getParameter('sylius_headless_oauth.providers.apple.enabled'));
        self::assertSame('%env(APPLE_CLIENT_ID)%', $this->container->getParameter('sylius_headless_oauth.providers.apple.client_id'));
        self::assertSame('%env(APPLE_TEAM_ID)%', $this->container->getParameter('sylius_headless_oauth.providers.apple.team_id'));
        self::assertSame('%env(APPLE_KEY_ID)%', $this->container->getParameter('sylius_headless_oauth.providers.apple.key_id'));
        self::assertSame('%env(APPLE_PRIVATE_KEY_PATH)%', $this->container->getParameter('sylius_headless_oauth.providers.apple.private_key_path'));
    }

    #[Test]
    public function canOverrideGoogleConfiguration(): void
    {
        $this->extension->load([
            [
                'providers' => [
                    'google' => [
                        'enabled' => false,
                        'client_id' => 'custom-google-id',
                        'client_secret' => 'custom-google-secret',
                    ],
                ],
            ],
        ], $this->container);

        self::assertFalse($this->container->getParameter('sylius_headless_oauth.providers.google.enabled'));
        self::assertSame('custom-google-id', $this->container->getParameter('sylius_headless_oauth.providers.google.client_id'));
        self::assertSame('custom-google-secret', $this->container->getParameter('sylius_headless_oauth.providers.google.client_secret'));
    }

    #[Test]
    public function canOverrideAppleConfiguration(): void
    {
        $this->extension->load([
            [
                'providers' => [
                    'apple' => [
                        'enabled' => false,
                        'client_id' => 'custom-apple-id',
                        'team_id' => 'custom-team-id',
                        'key_id' => 'custom-key-id',
                        'private_key_path' => '/custom/path/key.p8',
                    ],
                ],
            ],
        ], $this->container);

        self::assertFalse($this->container->getParameter('sylius_headless_oauth.providers.apple.enabled'));
        self::assertSame('custom-apple-id', $this->container->getParameter('sylius_headless_oauth.providers.apple.client_id'));
        self::assertSame('custom-team-id', $this->container->getParameter('sylius_headless_oauth.providers.apple.team_id'));
        self::assertSame('custom-key-id', $this->container->getParameter('sylius_headless_oauth.providers.apple.key_id'));
        self::assertSame('/custom/path/key.p8', $this->container->getParameter('sylius_headless_oauth.providers.apple.private_key_path'));
    }

    #[Test]
    public function registersAutoconfigurationForProviders(): void
    {
        $this->extension->load([], $this->container);

        $autoconfigured = $this->container->getAutoconfiguredInstanceof();

        self::assertArrayHasKey(OAuthProviderInterface::class, $autoconfigured);
        self::assertTrue($autoconfigured[OAuthProviderInterface::class]->hasTag('sylius_headless_oauth.provider'));
    }
}
