<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Resolver;

use Marac\SyliusHeadlessOAuthBundle\Provider\Model\OAuthUserData;
use Sylius\Component\Core\Model\ShopUserInterface;

interface UserResolverInterface
{
    public function resolve(OAuthUserData $userData): ShopUserInterface;
}
