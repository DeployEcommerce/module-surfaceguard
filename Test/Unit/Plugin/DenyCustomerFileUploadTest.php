<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Test\Unit\Plugin;

use DeployEcommerce\SurfaceGuard\Model\DenialLogger;
use DeployEcommerce\SurfaceGuard\Model\SwitchConfig;
use DeployEcommerce\SurfaceGuard\Plugin\DenyCustomerFileUpload;
use Magento\Customer\Model\FileUploaderFactory;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The entity type arriving as a plugin argument is what lets one guard serve two switches.
 */
class DenyCustomerFileUploadTest extends TestCase
{
    public function testAddressUploadIsDeniedByItsOwnSwitch(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_CUSTOMER_ADDRESS_FILE => false]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This operation is not available.');

        $plugin->aroundCreate(
            $this->factory(),
            static fn () => 'uploader',
            ['entityTypeCode' => 'customer_address']
        );
    }

    public function testCustomerUploadIsUnaffectedByTheAddressSwitch(): void
    {
        $plugin = $this->plugin([
            SwitchConfig::UPLOAD_CUSTOMER_ADDRESS_FILE => false,
            SwitchConfig::UPLOAD_CUSTOMER_CUSTOM_ATTR_FILE => true,
        ]);

        $result = $plugin->aroundCreate(
            $this->factory(),
            static fn () => 'uploader',
            ['entityTypeCode' => 'customer']
        );

        $this->assertSame('uploader', $result);
    }

    public function testCustomerUploadIsDeniedByItsOwnSwitch(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_CUSTOMER_CUSTOM_ATTR_FILE => false]);

        $this->expectException(LocalizedException::class);

        $plugin->aroundCreate(
            $this->factory(),
            static fn () => 'uploader',
            ['entityTypeCode' => 'customer']
        );
    }

    public function testUnknownEntityTypeIsLeftAlone(): void
    {
        $plugin = $this->plugin([
            SwitchConfig::UPLOAD_CUSTOMER_ADDRESS_FILE => false,
            SwitchConfig::UPLOAD_CUSTOMER_CUSTOM_ATTR_FILE => false,
        ]);

        $this->assertSame(
            'uploader',
            $plugin->aroundCreate(
                $this->factory(),
                static fn () => 'uploader',
                ['entityTypeCode' => 'something_else']
            )
        );

        $this->assertSame(
            'uploader',
            $plugin->aroundCreate($this->factory(), static fn () => 'uploader', [])
        );
    }

    private function factory(): FileUploaderFactory
    {
        return $this->getMockBuilder(FileUploaderFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @param array<string, bool> $switches
     */
    private function plugin(array $switches): DenyCustomerFileUpload
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path) => $switches[$path] ?? null
        );

        $denialLogger = new DenialLogger(
            $this->createMock(LoggerInterface::class),
            $this->createMock(RemoteAddress::class)
        );

        return new DenyCustomerFileUpload(new SwitchConfig($deploymentConfig), $denialLogger);
    }
}
