<?php declare(strict_types=1);

/*
 * This file is part of the Endereco Shopware 6 Client.
 *
 * (c) Endereco UG (haftungsbeschränkt)
 */

namespace Endereco\Shopware6Client\Tests\Unit\Service\AddressIntegrity\CustomerAddress;

use Endereco\Shopware6Client\Entity\CustomerAddress\CustomerAddressExtension;
use Endereco\Shopware6Client\Entity\EnderecoAddressExtension\CustomerAddress\EnderecoCustomerAddressExtensionCollection;
use Endereco\Shopware6Client\Entity\EnderecoAddressExtension\CustomerAddress\EnderecoCustomerAddressExtensionEntity;
use Endereco\Shopware6Client\Service\AddressIntegrity\CustomerAddress\AddressExtensionExistsInsurance;
use Endereco\Shopware6Client\Tests\Unit\Entity\CustomerAddress\CreatesCustomerAddressTrait;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Unit tests for AddressExtensionExistsInsurance
 * 
 * These tests ensure the insurance properly creates extensions that can be
 * processed by Shopware's collection system without getUniqueIdentifier errors.
 */
class AddressExtensionExistsInsuranceTest extends TestCase
{
    use CreatesCustomerAddressTrait;
    private AddressExtensionExistsInsurance $insurance;
    private EntityRepository $mockRepository;
    private Context $context;

    protected function setUp(): void
    {
        $this->mockRepository = $this->createMock(EntityRepository::class);
        $this->insurance = new AddressExtensionExistsInsurance($this->mockRepository);
        $this->context = Context::createDefaultContext();
    }


    #[DataProvider('addressScenarioProvider')]
    public function testEnsureCreatesValidExtensionForVariousScenarios(bool $hasExistingExtension): void
    {
        $addressId = Uuid::randomHex();
        $customerAddress = $this->createCustomerAddress($addressId);

        if ($hasExistingExtension) {
            $existingExtension = new EnderecoCustomerAddressExtensionEntity();
            $existingExtension->setAddressId($addressId);
            $customerAddress->addExtension(CustomerAddressExtension::ENDERECO_EXTENSION, $existingExtension);
            $this->mockRepository->expects($this->never())->method('upsert');
        } else {
            $this->mockRepository->expects($this->atLeastOnce())->method('upsert');
        }

        $result = $this->insurance->ensure($customerAddress, $this->context);

        $extension = $customerAddress->getExtension(CustomerAddressExtension::ENDERECO_EXTENSION);
        $this->assertInstanceOf(EnderecoCustomerAddressExtensionEntity::class, $extension);
        $this->assertSame($addressId, $extension->getAddressId());
        $this->assertNull($result); // Method returns void
        
        // Verify extension can be processed by collections
        $collection = new EnderecoCustomerAddressExtensionCollection();
        $collection->add($extension);
        $this->assertSame(1, $collection->count());
        $this->assertTrue($collection->has($addressId));
    }

    public static function addressScenarioProvider(): array
    {
        return [
            'new extension created' => [false],
            'existing extension preserved' => [true],
        ];
    }

    /**
     * Regression test for getUniqueIdentifier bug
     * 
     * Tests that the insurance creates extensions that can be processed by collections
     * without throwing TypeError. This prevents the production issue from recurring.
     */
    public function testEnsureCreatesValidExtensionThatCanBeProcessedByCollections(): void
    {
        $addressId = Uuid::randomHex();
        $customerAddress = $this->createCustomerAddress($addressId);

        $this->mockRepository->expects($this->atLeastOnce())->method('upsert');

        $result = $this->insurance->ensure($customerAddress, $this->context);

        $extension = $customerAddress->getExtension(CustomerAddressExtension::ENDERECO_EXTENSION);
        $this->assertInstanceOf(EnderecoCustomerAddressExtensionEntity::class, $extension);
        $this->assertSame($addressId, $extension->getAddressId());
        $this->assertNull($result); // Method returns void

        // Critical test: Extension must be processable by collections
        $collection = new EnderecoCustomerAddressExtensionCollection();
        $collection->add($extension); // This would throw TypeError if getUniqueIdentifier() returns null

        $this->assertSame(1, $collection->count());
        $this->assertTrue($collection->has($addressId));
    }


    /**
     * Tests that ensure doesn't create extension if one already exists
     * 
     * Validates the conditional creation logic.
     */
    public function testEnsureDoesNotCreateExtensionIfAlreadyExists(): void
    {
        $addressId = Uuid::randomHex();
        $customerAddress = $this->createCustomerAddress($addressId);

        // Add existing extension
        $existingExtension = new EnderecoCustomerAddressExtensionEntity();
        $existingExtension->setAddressId($addressId);
        $customerAddress->addExtension(CustomerAddressExtension::ENDERECO_EXTENSION, $existingExtension);

        $this->mockRepository->expects($this->never())->method('upsert');

        $result = $this->insurance->ensure($customerAddress, $this->context);

        // Should still have the original extension
        $extension = $customerAddress->getExtension(CustomerAddressExtension::ENDERECO_EXTENSION);
        $this->assertSame($existingExtension, $extension);
        $this->assertNull($result);
    }


    #[DataProvider('multipleAddressProvider')]
    public function testEnsureWorksForMultipleAddresses(int $addressCount): void
    {
        $addresses = [];
        for ($i = 0; $i < $addressCount; $i++) {
            $addresses[] = $this->createCustomerAddress();
        }

        $this->mockRepository->expects($this->atLeastOnce())->method('upsert');

        $extensions = [];
        foreach ($addresses as $address) {
            $result = $this->insurance->ensure($address, $this->context);
            $this->assertNull($result);
            $extensions[] = $address->getExtension(CustomerAddressExtension::ENDERECO_EXTENSION);
        }

        // All extensions should be valid and work in the same collection
        $collection = new EnderecoCustomerAddressExtensionCollection();
        foreach ($extensions as $extension) {
            $this->assertInstanceOf(EnderecoCustomerAddressExtensionEntity::class, $extension);
            $collection->add($extension);
        }

        $this->assertSame($addressCount, $collection->count());
    }

    public static function multipleAddressProvider(): array
    {
        return [
            'single address' => [1],
            'two addresses' => [2],
            'multiple addresses' => [5],
        ];
    }

    /**
     * Tests that created extensions have proper default values
     * 
     * Validates the default state of created extensions.
     */
    public function testCreatedExtensionHasProperDefaultValues(): void
    {
        $addressId = Uuid::randomHex();
        $customerAddress = $this->createCustomerAddress($addressId);

        $this->mockRepository->expects($this->atLeastOnce())->method('upsert');

        $result = $this->insurance->ensure($customerAddress, $this->context);
        $this->assertNull($result);

        $extension = $customerAddress->getExtension(CustomerAddressExtension::ENDERECO_EXTENSION);
        
        $this->assertSame($addressId, $extension->getAddressId());
        $this->assertSame('not-checked', $extension->getAmsStatus());
        $this->assertSame([], $extension->getAmsPredictions());
        $this->assertSame($customerAddress, $extension->getAddress());
    }

}
