<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Functional\Factory;

use Marac\SyliusHeadlessOAuthBundle\Tests\Functional\Entity\TestShopUser;
use Sylius\Component\Resource\Factory\FactoryInterface;

final class TestShopUserFactory implements FactoryInterface
{
    public function createNew(): TestShopUser
    {
        return new TestShopUser();
    }
}
