<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Resolver;

use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;

interface UserResolverInterface
{
    public function resolve(OAuthUserData $userData): UserResolveResult;
}
