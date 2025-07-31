<?php declare(strict_types=1);

/*
 * This file is part of the Endereco Shopware 6 Client.
 *
 * (c) Endereco UG (haftungsbeschränkt)
 */

namespace Endereco\Shopware6Client\Tests\Unit\Entity\CustomerAddress;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Trait for creating CustomerAddressEntity instances in tests
 * 
 * Provides a consistent way to create customer addresses with proper UUIDs
 * for testing across multiple test classes.
 */
trait CreatesCustomerAddressTrait
{
    /**
     * Creates a CustomerAddressEntity with proper UUID for testing
     */
    private function createCustomerAddress(?string $id = null): CustomerAddressEntity
    {
        $address = new CustomerAddressEntity();
        $addressId = $id ?? Uuid::randomHex();
        $address->setId(Uuid::fromHexToBytes($addressId));
        $address->assign(['id' => $addressId]); // Keep hex version accessible
        return $address;
    }
}
