<?php declare(strict_types=1);

/*
 * This file is part of the Endereco Shopware 6 Client.
 *
 * (c) Endereco UG (haftungsbeschränkt)
 */

namespace Endereco\Shopware6Client\Tests\Unit\Entity\CustomerAddress;

use Endereco\Shopware6Client\Entity\EnderecoAddressExtension\CustomerAddress\EnderecoCustomerAddressExtensionCollection;
use Endereco\Shopware6Client\Entity\EnderecoAddressExtension\CustomerAddress\EnderecoCustomerAddressExtensionEntity;
use Endereco\Shopware6Client\Tests\Unit\Entity\CustomerAddress\CreatesCustomerAddressTrait;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use TypeError;

/**
 * Tests for EnderecoCustomerAddressExtensionCollection
 * 
 * These tests ensure the collection properly handles extensions and prevents
 * the getUniqueIdentifier TypeError that caused production issues.
 */
class EnderecoCustomerAddressExtensionCollectionTest extends TestCase
{
    use CreatesCustomerAddressTrait;

    #[DataProvider('collectionScenarioProvider')]
    public function testCollectionOperationsWithVariousExtensions(int $extensionCount, array $amsStatuses): void
    {
        $extensions = [];
        $addressIds = [];
        
        for ($i = 0; $i < $extensionCount; $i++) {
            $addressId = Uuid::randomHex();
            $addressIds[] = $addressId;
            $extension = EnderecoCustomerAddressExtensionEntity::createWithDefaultValues($this->createCustomerAddress($addressId));
            $extension->setAmsStatus($amsStatuses[$i % count($amsStatuses)]);
            $extensions[] = $extension;
        }

        $collection = new EnderecoCustomerAddressExtensionCollection($extensions);
        
        $this->assertSame($extensionCount, $collection->count());
        
        foreach ($addressIds as $addressId) {
            $this->assertTrue($collection->has($addressId));
        }
        
        // Test iteration
        $iteratedCount = 0;
        foreach ($collection as $key => $extension) {
            $this->assertContains($key, $addressIds);
            $this->assertInstanceOf(EnderecoCustomerAddressExtensionEntity::class, $extension);
            $this->assertSame($key, $extension->getUniqueIdentifier());
            $iteratedCount++;
        }
        $this->assertSame($extensionCount, $iteratedCount);
    }

    public static function collectionScenarioProvider(): array
    {
        return [
            'single extension' => [1, ['not-checked']],
            'multiple extensions with same status' => [3, ['address_correct']],
            'mixed statuses' => [4, ['not-checked', 'address_correct', 'address_needs_correction']],
        ];
    }

    /**
     * Regression test for getUniqueIdentifier bug
     * 
     * This test simulates the exact scenario that caused the TypeError:
     * manually created extension being added to EntityCollection.
     */
    public function testCollectionCanAddManuallyCreatedExtensions(): void
    {
        $addressId = Uuid::randomHex();
        $extension = EnderecoCustomerAddressExtensionEntity::createWithDefaultValues($this->createCustomerAddress($addressId));
        
        $collection = new EnderecoCustomerAddressExtensionCollection();
        
        // This would throw TypeError: getUniqueIdentifier(): Return value must be of type string, null returned
        // if the entity doesn't properly implement getUniqueIdentifier()
        $collection->add($extension);
        
        $this->assertSame(1, $collection->count());
        $this->assertTrue($collection->has($addressId));
        $this->assertSame($extension, $collection->get($addressId));
    }

    /**
     * Tests collection behavior with multiple extensions
     * 
     * Ensures multiple extensions can be processed without unique identifier conflicts.
     */
    public function testCollectionCanAddMultipleExtensions(): void
    {
        $addressIds = [Uuid::randomHex(), Uuid::randomHex(), Uuid::randomHex()];
        $extensions = [];
        
        foreach ($addressIds as $addressId) {
            $extensions[] = EnderecoCustomerAddressExtensionEntity::createWithDefaultValues($this->createCustomerAddress($addressId));
        }

        $collection = new EnderecoCustomerAddressExtensionCollection();
        foreach ($extensions as $extension) {
            $collection->add($extension);
        }
        
        $this->assertSame(3, $collection->count());
        foreach ($addressIds as $addressId) {
            $this->assertTrue($collection->has($addressId));
        }
    }

    /**
     * Tests that collection properly handles duplicate unique identifiers
     * 
     * When two extensions have the same addressId, the second should replace the first.
     */
    public function testCollectionReplacesExtensionWithSameKey(): void
    {
        $addressId = Uuid::randomHex();
        
        $extension1 = EnderecoCustomerAddressExtensionEntity::createWithDefaultValues($this->createCustomerAddress($addressId));
        $extension1->setStreet('Lindenstraße');
        
        $extension2 = EnderecoCustomerAddressExtensionEntity::createWithDefaultValues($this->createCustomerAddress($addressId));
        $extension2->setStreet('Schulstraße');
        
        $collection = new EnderecoCustomerAddressExtensionCollection();
        $collection->add($extension1);
        $collection->add($extension2);
        
        // Should only have one item (second replaces first)
        $this->assertSame(1, $collection->count());
        $this->assertTrue($collection->has($addressId));
        
        $storedExtension = $collection->get($addressId);
        $this->assertSame('Schulstraße', $storedExtension->getStreet());
        $this->assertSame($extension2, $storedExtension);
    }

    #[DataProvider('iterationSizeProvider')]
    public function testCollectionIterationWorksCorrectly(int $extensionCount): void
    {
        $extensions = [];
        $expectedAddressIds = [];
        
        for ($i = 0; $i < $extensionCount; $i++) {
            $addressId = Uuid::randomHex();
            $expectedAddressIds[] = $addressId;
            $extensions[] = EnderecoCustomerAddressExtensionEntity::createWithDefaultValues($this->createCustomerAddress($addressId));
        }
        
        $collection = new EnderecoCustomerAddressExtensionCollection($extensions);
        
        $this->assertSame($extensionCount, $collection->count());
        
        $iteratedKeys = [];
        foreach ($collection as $key => $extension) {
            $iteratedKeys[] = $key;
            $this->assertInstanceOf(EnderecoCustomerAddressExtensionEntity::class, $extension);
            $this->assertSame($key, $extension->getUniqueIdentifier());
        }
        
        $this->assertSame($extensionCount, count($iteratedKeys));
        $this->assertSame([], array_diff($expectedAddressIds, $iteratedKeys));
    }

    public static function iterationSizeProvider(): array
    {
        return [
            'single extension' => [1],
            'multiple extensions' => [3],
        ];
    }

    #[DataProvider('filterScenarioProvider')]
    public function testCollectionFilteringWorksCorrectly(array $statuses, string $filterStatus, int $expectedCount): void
    {
        $extensions = [];
        foreach ($statuses as $status) {
            $extension = EnderecoCustomerAddressExtensionEntity::createWithDefaultValues($this->createCustomerAddress());
            $extension->setAmsStatus($status);
            $extensions[] = $extension;
        }
        
        $collection = new EnderecoCustomerAddressExtensionCollection($extensions);
        
        $filteredCollection = $collection->filter(function ($extension) use ($filterStatus) {
            return $extension->getAmsStatus() === $filterStatus;
        });
        
        $this->assertSame($expectedCount, $filteredCollection->count());
        
        // Verify all filtered items have the expected status
        foreach ($filteredCollection as $extension) {
            $this->assertSame($filterStatus, $extension->getAmsStatus());
        }
    }

    public static function filterScenarioProvider(): array
    {
        return [
            'filter correct addresses' => [
                ['address_correct', 'not-checked', 'address_correct'],
                'address_correct',
                2
            ],
            'filter unchecked addresses' => [
                ['not-checked', 'address_correct', 'address_needs_correction'],
                'not-checked',
                1
            ],
            'no matches' => [
                ['address_correct', 'address_correct'],
                'not-checked',
                0
            ],
        ];
    }

    /**
     * Tests collection removal operations
     * 
     * Ensures elements can be safely removed from collection.
     */
    public function testCollectionRemovalWorksCorrectly(): void
    {
        $addressId1 = Uuid::randomHex();
        $addressId2 = Uuid::randomHex();
        
        $extension1 = EnderecoCustomerAddressExtensionEntity::createWithDefaultValues($this->createCustomerAddress($addressId1));
        $extension2 = EnderecoCustomerAddressExtensionEntity::createWithDefaultValues($this->createCustomerAddress($addressId2));
        
        $collection = new EnderecoCustomerAddressExtensionCollection([$extension1, $extension2]);
        
        $this->assertSame(2, $collection->count());
        
        $collection->remove($addressId1);
        
        $this->assertSame(1, $collection->count());
        $this->assertFalse($collection->has($addressId1));
        $this->assertTrue($collection->has($addressId2));
        $this->assertSame($extension2, $collection->get($addressId2));
    }

    /**
     * Tests that extension with associated CustomerAddressEntity works correctly in collection
     * 
     * Validates collection behavior when extension has full association data.
     */
    public function testCollectionWorksWithAssociatedAddressExtensions(): void
    {
        $addressId = Uuid::randomHex();
        $customerAddress = $this->createCustomerAddress($addressId);
        
        $extension = EnderecoCustomerAddressExtensionEntity::createWithDefaultValues($customerAddress);
        
        $collection = new EnderecoCustomerAddressExtensionCollection();
        $collection->add($extension);
        
        $this->assertSame(1, $collection->count());
        $this->assertTrue($collection->has($addressId));
        $this->assertSame($extension, $collection->get($addressId));
        $this->assertSame($customerAddress, $extension->getAddress());
    }

    /**
     * Tests that collection returns correct API alias
     * 
     * Validates the API alias implementation.
     */
    public function testCollectionApiAlias(): void
    {
        $collection = new EnderecoCustomerAddressExtensionCollection();
        
        $this->assertSame('endereco_customer_address_extension_collection', $collection->getApiAlias());
    }


    #[DataProvider('iterationSizeProvider')]
    public function testCollectionConstructorWithInitialExtensions(int $extensionCount): void
    {
        $extensions = [];
        $addressIds = [];
        
        for ($i = 0; $i < $extensionCount; $i++) {
            $addressId = Uuid::randomHex();
            $addressIds[] = $addressId;
            $extensions[] = EnderecoCustomerAddressExtensionEntity::createWithDefaultValues($this->createCustomerAddress($addressId));
        }
        
        $collection = new EnderecoCustomerAddressExtensionCollection($extensions);
        
        $this->assertSame($extensionCount, $collection->count());
        foreach ($addressIds as $addressId) {
            $this->assertTrue($collection->has($addressId));
        }
    }
}
