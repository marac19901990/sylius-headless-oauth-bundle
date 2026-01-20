<?php

declare(strict_types=1);

namespace Marac\SyliusHeadlessOAuthBundle\Tests\Functional\Factory;

use Marac\SyliusHeadlessOAuthBundle\Tests\Functional\Entity\TestCustomer;
use Sylius\Component\Resource\Factory\FactoryInterface;

final class TestCustomerFactory implements FactoryInterface
{
    public function createNew(): TestCustomer
    {
        return new TestCustomer();
    }
}
