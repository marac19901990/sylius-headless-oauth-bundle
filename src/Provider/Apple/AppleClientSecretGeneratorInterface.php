<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Provider\Apple;

interface AppleClientSecretGeneratorInterface
{
    /**
     * Generate a client_secret JWT for Apple Sign-In.
     *
     * @param int $expirySeconds How long the token should be valid (max 6 months)
     */
    public function generate(int $expirySeconds = 3600): string;
}
