<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Functional;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Lexik\Bundle\JWTAuthenticationBundle\LexikJWTAuthenticationBundle;
use Marac\SyliusHeadlessOAuthBundle\SyliusHeadlessOAuthBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;

/**
 * Minimal Symfony kernel for functional testing of the OAuth bundle.
 *
 * This kernel:
 * - Registers required bundles (FrameworkBundle, SecurityBundle, ApiPlatform, LexikJWT, DoctrineBundle)
 * - Uses in-memory SQLite database
 * - Loads test entity mappings for TestCustomer/TestShopUser
 * - Provides mocked Sylius services pointing to test entities
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new ApiPlatformBundle();
        yield new LexikJWTAuthenticationBundle();
        yield new SyliusHeadlessOAuthBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/sylius_headless_oauth_test/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/sylius_headless_oauth_test/logs';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'secret' => 'test_secret_for_functional_tests',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => [
                'log' => true,
            ],
            'router' => [
                'utf8' => true,
            ],
            'validation' => [
                'email_validation_mode' => 'html5',
            ],
            'property_access' => true,
            'messenger' => [
                'default_bus' => 'messenger.bus.default',
                'buses' => [
                    'messenger.bus.default' => [],
                ],
            ],
        ]);

        $container->extension('security', [
            'password_hashers' => [
                'Marac\SyliusHeadlessOAuthBundle\Tests\Functional\Entity\TestShopUser' => [
                    'algorithm' => 'auto',
                ],
            ],
            'providers' => [
                'test_user_provider' => [
                    'entity' => [
                        'class' => 'Marac\SyliusHeadlessOAuthBundle\Tests\Functional\Entity\TestShopUser',
                        'property' => 'email',
                    ],
                ],
            ],
            'firewalls' => [
                'main' => [
                    'lazy' => true,
                    'stateless' => true,
                    'provider' => 'test_user_provider',
                    'jwt' => [],
                ],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => [
                'driver' => 'pdo_sqlite',
                'path' => '%kernel.cache_dir%/test.db',
            ],
            'orm' => [
                'auto_generate_proxy_classes' => true,
                'auto_mapping' => false,
                'mappings' => [
                    'TestEntities' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => '%kernel.project_dir%/tests/Functional/Entity',
                        'prefix' => 'Marac\SyliusHeadlessOAuthBundle\Tests\Functional\Entity',
                        'alias' => 'Test',
                    ],
                ],
            ],
        ]);

        $projectDir = $this->getProjectDir();
        $container->extension('api_platform', [
            'title' => 'OAuth Test API',
            'version' => '1.0.0',
            'formats' => [
                'jsonld' => ['mime_types' => ['application/ld+json']],
                'json' => ['mime_types' => ['application/json']],
            ],
            'defaults' => [
                'stateless' => true,
                'cache_headers' => [
                    'vary' => ['Content-Type', 'Authorization', 'Origin'],
                ],
            ],
            'mapping' => [
                'paths' => [
                    $projectDir . '/src/Api/Resource',
                ],
            ],
        ]);

        $container->extension('lexik_jwt_authentication', [
            'secret_key' => $projectDir . '/tests/Functional/config/jwt/private.pem',
            'public_key' => $projectDir . '/tests/Functional/config/jwt/public.pem',
            'pass_phrase' => '',
        ]);

        $container->extension('sylius_headless_o_auth', [
            'security' => [
                'allowed_redirect_uris' => ['https://example.com/callback'],
                'verify_apple_jwt' => false,
            ],
            'providers' => [
                'google' => [
                    'enabled' => true,
                    'client_id' => 'test_google_client_id',
                    'client_secret' => 'test_google_client_secret',
                ],
                'apple' => [
                    'enabled' => true,
                    'client_id' => 'test_apple_client_id',
                    'team_id' => 'test_team_id',
                    'key_id' => 'test_key_id',
                    'private_key_path' => $projectDir . '/tests/Functional/config/jwt/private.pem',
                ],
                'facebook' => [
                    'enabled' => true,
                    'client_id' => 'test_facebook_client_id',
                    'client_secret' => 'test_facebook_client_secret',
                ],
            ],
        ]);

        // Load test services
        $loader->load(__DIR__ . '/config/services_test.yaml');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // Import API Platform routes
        $routes->import('.', 'api_platform');
    }
}
