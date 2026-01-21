<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Marac\SyliusHeadlessOAuthBundle\Entity\OAuthIdentity;
use Marac\SyliusHeadlessOAuthBundle\Tests\Functional\Entity\TestCustomer;
use Marac\SyliusHeadlessOAuthBundle\Tests\Functional\Mock\MockHttpClientFactory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use const JSON_THROW_ON_ERROR;

/**
 * Functional tests for OAuth endpoints.
 *
 * These tests verify the full OAuth flow from HTTP request to JWT token response,
 * using a real Symfony kernel with mocked HTTP client for OAuth provider calls.
 *
 * @requires extension pdo_sqlite
 */
#[RequiresPhpExtension('pdo_sqlite')]
class OAuthEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->entityManager = $entityManager;

        // Create the schema in the in-memory SQLite DB
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        // Reset mock handler for each test
        MockHttpClientFactory::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->entityManager->close();
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testGoogleOAuthSuccess(): void
    {
        // Queue mock responses for Google OAuth flow
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createGoogleTokenResponse());
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createGoogleUserinfoResponse());

        $this->client->request(
            'POST',
            '/auth/oauth/google',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'code' => 'valid_google_code',
                'redirectUri' => 'https://example.com/callback',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        $content = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $content);
        self::assertArrayHasKey('refreshToken', $content);
        self::assertArrayHasKey('customerId', $content);
        self::assertNotEmpty($content['token']);
        self::assertEquals('google_refresh_token_456', $content['refreshToken']);

        // Verify customer was created in database
        $customer = $this->entityManager->getRepository(TestCustomer::class)->findOneBy(['email' => 'test@example.com']);
        self::assertNotNull($customer);
        self::assertEquals('Test', $customer->getFirstName());
        self::assertEquals('User', $customer->getLastName());

        // Verify OAuth identity was created
        $oauthIdentity = $this->findOAuthIdentity('google', 'google_user_123');
        self::assertNotNull($oauthIdentity);
        self::assertSame($customer, $oauthIdentity->getCustomer());
    }

    public function testFacebookOAuthSuccess(): void
    {
        // Queue mock responses for Facebook OAuth flow
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createFacebookTokenResponse());
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createFacebookUserinfoResponse());

        $this->client->request(
            'POST',
            '/auth/oauth/facebook',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'code' => 'valid_facebook_code',
                'redirectUri' => 'https://example.com/callback',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        $content = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $content);
        self::assertArrayHasKey('customerId', $content);
        self::assertNotEmpty($content['token']);

        // Verify customer was created in database
        $customer = $this->entityManager->getRepository(TestCustomer::class)->findOneBy(['email' => 'test@example.com']);
        self::assertNotNull($customer);

        // Verify OAuth identity was created
        $oauthIdentity = $this->findOAuthIdentity('facebook', 'facebook_user_123');
        self::assertNotNull($oauthIdentity);
        self::assertSame($customer, $oauthIdentity->getCustomer());
    }

    public function testAppleOAuthSuccess(): void
    {
        // Queue mock response for Apple OAuth flow
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createAppleTokenResponse());

        $this->client->request(
            'POST',
            '/auth/oauth/apple',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'code' => 'valid_apple_code',
                'redirectUri' => 'https://example.com/callback',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        $content = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $content);
        self::assertArrayHasKey('refreshToken', $content);
        self::assertNotEmpty($content['token']);

        // Verify customer was created in database
        $customer = $this->entityManager->getRepository(TestCustomer::class)->findOneBy(['email' => 'test@privaterelay.appleid.com']);
        self::assertNotNull($customer);

        // Verify OAuth identity was created
        $oauthIdentity = $this->findOAuthIdentity('apple', 'apple_user_123');
        self::assertNotNull($oauthIdentity);
        self::assertSame($customer, $oauthIdentity->getCustomer());
    }

    public function testInvalidProviderReturns404(): void
    {
        $this->client->request(
            'POST',
            '/auth/oauth/invalid_provider',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'code' => 'some_code',
                'redirectUri' => 'https://example.com/callback',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        // Provider not supported should result in an error (400 or 404)
        self::assertTrue(
            $response->isClientError(),
            'Expected a client error response for invalid provider',
        );
    }

    public function testMissingCodeReturnsValidationError(): void
    {
        $this->client->request(
            'POST',
            '/auth/oauth/google',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'redirectUri' => 'https://example.com/callback',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        $statusCode = $response->getStatusCode();

        // API Platform returns 422 for validation errors
        self::assertEquals(422, $statusCode, 'Expected 422 Unprocessable Entity for missing code');
    }

    public function testMissingRedirectUriReturnsValidationError(): void
    {
        $this->client->request(
            'POST',
            '/auth/oauth/google',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'code' => 'some_code',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        $statusCode = $response->getStatusCode();

        // API Platform returns 422 for validation errors
        self::assertEquals(422, $statusCode, 'Expected 422 Unprocessable Entity for missing redirectUri');
    }

    public function testInvalidRedirectUriReturnsError(): void
    {
        $this->client->request(
            'POST',
            '/auth/oauth/google',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'code' => 'some_code',
                'redirectUri' => 'https://malicious.com/callback',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        // Redirect URI not in allowlist should be rejected
        self::assertTrue(
            $response->isClientError(),
            'Expected a client error response for invalid redirect URI',
        );
    }

    public function testInvalidCodeReturnsError(): void
    {
        // Queue mock error response
        MockHttpClientFactory::appendResponse(
            MockHttpClientFactory::createErrorResponse(400, 'invalid_grant', 'The authorization code has expired'),
        );

        $this->client->request(
            'POST',
            '/auth/oauth/google',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'code' => 'invalid_code',
                'redirectUri' => 'https://example.com/callback',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        self::assertTrue(
            $response->isServerError() || $response->isClientError(),
            'Expected an error response for invalid code',
        );
    }

    public function testNewUserCreatesCustomer(): void
    {
        // Ensure no customer exists
        $existingCustomer = $this->entityManager->getRepository(TestCustomer::class)->findOneBy(['email' => 'newuser@example.com']);
        self::assertNull($existingCustomer);

        // Queue mock responses
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createGoogleTokenResponse());
        MockHttpClientFactory::appendResponse(
            MockHttpClientFactory::createGoogleUserinfoResponse(
                'google_new_user_id',
                'newuser@example.com',
                'New',
                'User',
            ),
        );

        $this->client->request(
            'POST',
            '/auth/oauth/google',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'code' => 'valid_code',
                'redirectUri' => 'https://example.com/callback',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        // Verify new customer was created
        $customer = $this->entityManager->getRepository(TestCustomer::class)->findOneBy(['email' => 'newuser@example.com']);
        self::assertNotNull($customer);
        self::assertEquals('New', $customer->getFirstName());
        self::assertEquals('User', $customer->getLastName());
        self::assertNotNull($customer->getUser());

        // Verify OAuth identity was created
        $oauthIdentity = $this->findOAuthIdentity('google', 'google_new_user_id');
        self::assertNotNull($oauthIdentity);
        self::assertSame($customer, $oauthIdentity->getCustomer());
    }

    public function testExistingUserReturnsCustomerId(): void
    {
        // Create existing customer with OAuth identity
        $existingCustomer = new TestCustomer();
        $existingCustomer->setEmail('existing@example.com');
        $existingCustomer->setFirstName('Existing');
        $existingCustomer->setLastName('User');

        $oauthIdentity = new OAuthIdentity();
        $oauthIdentity->setProvider('google');
        $oauthIdentity->setIdentifier('existing_google_id');
        $oauthIdentity->setCustomer($existingCustomer);

        $this->entityManager->persist($existingCustomer);
        $this->entityManager->persist($oauthIdentity);
        $this->entityManager->flush();

        $existingId = $existingCustomer->getId();

        // Queue mock responses for returning user
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createGoogleTokenResponse());
        MockHttpClientFactory::appendResponse(
            MockHttpClientFactory::createGoogleUserinfoResponse(
                'existing_google_id',
                'existing@example.com',
                'Existing',
                'User',
            ),
        );

        $this->client->request(
            'POST',
            '/auth/oauth/google',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'code' => 'valid_code',
                'redirectUri' => 'https://example.com/callback',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        $content = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertResponseIsSuccessful();
        self::assertEquals($existingId, $content['customerId']);

        // Verify no duplicate customer was created
        $customers = $this->entityManager->getRepository(TestCustomer::class)->findBy(['email' => 'existing@example.com']);
        self::assertCount(1, $customers);
    }

    public function testProviderLinkingForExistingEmailUser(): void
    {
        // Create existing customer without OAuth identity (e.g., registered via email)
        $existingCustomer = new TestCustomer();
        $existingCustomer->setEmail('link@example.com');
        $existingCustomer->setFirstName('Link');
        $existingCustomer->setLastName('Test');
        // No OAuth identity linked

        $this->entityManager->persist($existingCustomer);
        $this->entityManager->flush();

        $existingId = $existingCustomer->getId();

        // Queue mock responses
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createGoogleTokenResponse());
        MockHttpClientFactory::appendResponse(
            MockHttpClientFactory::createGoogleUserinfoResponse(
                'new_google_id_for_link',
                'link@example.com',
                'Link',
                'Test',
            ),
        );

        $this->client->request(
            'POST',
            '/auth/oauth/google',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'code' => 'valid_code',
                'redirectUri' => 'https://example.com/callback',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        $content = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertResponseIsSuccessful();
        self::assertEquals($existingId, $content['customerId']);

        // Refresh entity from database
        $this->entityManager->clear();
        $updatedCustomer = $this->entityManager->getRepository(TestCustomer::class)->find($existingId);
        self::assertNotNull($updatedCustomer);

        // Verify OAuth identity was linked to existing customer
        $oauthIdentity = $this->findOAuthIdentity('google', 'new_google_id_for_link');
        self::assertNotNull($oauthIdentity);
        self::assertEquals($existingId, $oauthIdentity->getCustomer()->getId());
    }

    public function testRefreshTokenEndpoint(): void
    {
        // First, create a user through normal OAuth flow
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createGoogleTokenResponse());
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createGoogleUserinfoResponse());

        $this->client->request(
            'POST',
            '/auth/oauth/google',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'code' => 'valid_code',
                'redirectUri' => 'https://example.com/callback',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        // Now test the refresh endpoint
        // Queue mock responses for refresh
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createGoogleTokenResponse('new_access_token', 'google_refresh_token_456'));
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createGoogleUserinfoResponse());

        $this->client->request(
            'POST',
            '/auth/oauth/google/refresh',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'refreshToken' => 'google_refresh_token_456',
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        $content = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $content);
        self::assertNotEmpty($content['token']);
    }

    public function testOAuthIdentityPersistence(): void
    {
        // Test that OAuthIdentity entities are correctly persisted
        $customer = new TestCustomer();
        $customer->setEmail('identity-test@example.com');

        $this->entityManager->persist($customer);

        // Test Google identity
        $googleIdentity = new OAuthIdentity();
        $googleIdentity->setProvider('google');
        $googleIdentity->setIdentifier('google_123');
        $googleIdentity->setCustomer($customer);
        $googleIdentity->setConnectedAt(new DateTimeImmutable());
        $this->entityManager->persist($googleIdentity);

        // Test Apple identity
        $appleIdentity = new OAuthIdentity();
        $appleIdentity->setProvider('apple');
        $appleIdentity->setIdentifier('apple_456');
        $appleIdentity->setCustomer($customer);
        $appleIdentity->setConnectedAt(new DateTimeImmutable());
        $this->entityManager->persist($appleIdentity);

        // Test Facebook identity
        $facebookIdentity = new OAuthIdentity();
        $facebookIdentity->setProvider('facebook');
        $facebookIdentity->setIdentifier('facebook_789');
        $facebookIdentity->setCustomer($customer);
        $this->entityManager->persist($facebookIdentity);

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Reload and verify
        $loadedGoogleIdentity = $this->findOAuthIdentity('google', 'google_123');
        $loadedAppleIdentity = $this->findOAuthIdentity('apple', 'apple_456');
        $loadedFacebookIdentity = $this->findOAuthIdentity('facebook', 'facebook_789');

        self::assertNotNull($loadedGoogleIdentity);
        self::assertNotNull($loadedAppleIdentity);
        self::assertNotNull($loadedFacebookIdentity);

        // All should belong to the same customer
        $loadedCustomer = $this->entityManager->getRepository(TestCustomer::class)->findOneBy(['email' => 'identity-test@example.com']);
        self::assertNotNull($loadedCustomer);
        self::assertEquals($loadedCustomer->getId(), $loadedGoogleIdentity->getCustomer()->getId());
        self::assertEquals($loadedCustomer->getId(), $loadedAppleIdentity->getCustomer()->getId());
        self::assertEquals($loadedCustomer->getId(), $loadedFacebookIdentity->getCustomer()->getId());
    }

    public function testStateParameterIsEchoed(): void
    {
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createGoogleTokenResponse());
        MockHttpClientFactory::appendResponse(MockHttpClientFactory::createGoogleUserinfoResponse());

        $state = 'random_csrf_state_123';

        $this->client->request(
            'POST',
            '/auth/oauth/google',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'code' => 'valid_code',
                'redirectUri' => 'https://example.com/callback',
                'state' => $state,
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        $content = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('state', $content);
        self::assertEquals($state, $content['state']);
    }

    /**
     * Helper to find an OAuth identity by provider and identifier.
     */
    private function findOAuthIdentity(string $provider, string $identifier): ?OAuthIdentity
    {
        return $this->entityManager->getRepository(OAuthIdentity::class)->findOneBy([
            'provider' => $provider,
            'identifier' => $identifier,
        ]);
    }
}
