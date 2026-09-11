<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Test\Unit\Plugin;

use DeployEcommerce\SurfaceGuard\Model\DenialLogger;
use DeployEcommerce\SurfaceGuard\Model\SwitchConfig;
use DeployEcommerce\SurfaceGuard\Plugin\DenyFileOptionBackstop;
use Magento\Catalog\Model\Product\Option;
use Magento\Catalog\Model\Product\Option\Type\File\ValidatorFile;
use Magento\Catalog\Model\Product\Option\Type\File\ValidatorInfo;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\File\Http as FileTransfer;
use Magento\Framework\HTTP\Adapter\FileTransferFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SG-5: the backstop denies a file-option upload even when no controller guard fires,
 * without blocking a purchase that carries no upload at all.
 */
class DenyFileOptionBackstopTest extends TestCase
{
    public function testProceedsUntouchedWhenBothSwitchesAllow(): void
    {
        $plugin = $this->plugin([
            SwitchConfig::UPLOAD_CART_ADD_FILE => true,
            SwitchConfig::UPLOAD_GUEST_CART_ITEMS_FILE => true,
        ]);

        $called = false;
        $proceed = static function ($first, $option) use (&$called) {
            $called = true;
            return ['validated'];
        };

        $result = $plugin->aroundValidate(
            $this->createMock(ValidatorFile::class),
            $proceed,
            $this->processingParams(),
            $this->option()
        );

        $this->assertTrue($called, 'The original validator must run when nothing is switched off.');
        $this->assertSame(['validated'], $result);
    }

    /**
     * The regression this guards: core calls ValidatorFile::validate() for an optional file
     * option even when nothing was uploaded, and File.php relies on the specific exception
     * type core throws to let the purchase continue. Denying here would fail add-to-cart for
     * every customer of a product that merely offers an optional file option.
     */
    public function testOptionalFileOptionWithNoUploadIsNotDenied(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_CART_ADD_FILE => false], uploaded: false);

        $called = false;
        $proceed = static function ($first, $option) use (&$called) {
            $called = true;
            return ['core-decides'];
        };

        $result = $plugin->aroundValidate(
            $this->createMock(ValidatorFile::class),
            $proceed,
            $this->processingParams(),
            $this->option()
        );

        $this->assertTrue($called, 'Core must be left to handle the no-upload case.');
        $this->assertSame(['core-decides'], $result);
    }

    public function testActualUploadIsDeniedWhenCartAddSwitchIsOff(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_CART_ADD_FILE => false], uploaded: true);

        $proceed = static function () {
            self::fail('The original validator must not run once a switch has denied.');
        };

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This operation is not available.');

        $plugin->aroundValidate(
            $this->createMock(ValidatorFile::class),
            $proceed,
            $this->processingParams(),
            $this->option()
        );
    }

    public function testActualUploadIsDeniedWhenRestSwitchIsOff(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_GUEST_CART_ITEMS_FILE => false], uploaded: true);

        $this->expectException(LocalizedException::class);

        $plugin->aroundValidate(
            $this->createMock(ValidatorFile::class),
            static fn () => null,
            $this->processingParams(),
            $this->option()
        );
    }

    /**
     * ValidatorInfo is only reached when File.php already holds file info, so there is no
     * empty case to protect and the denial stays unconditional.
     */
    public function testReMaterializePathIsDeniedWithoutAnUploadCheck(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_GUEST_CART_ITEMS_FILE => false], uploaded: false);

        $proceed = static function () {
            self::fail('The original validator must not run once a switch has denied.');
        };

        $this->expectException(LocalizedException::class);

        $plugin->aroundValidate(
            $this->createMock(ValidatorInfo::class),
            $proceed,
            ['quote_path' => 'custom_options/quote/s/p/spec.gif'],
            $this->option()
        );
    }

    public function testDenialMessageLeaksNothingInternal(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_CART_ADD_FILE => false], uploaded: true);

        try {
            $plugin->aroundValidate(
                $this->createMock(ValidatorFile::class),
                static fn () => null,
                $this->processingParams(),
                $this->option()
            );
            $this->fail('Expected the backstop to deny.');
        } catch (LocalizedException $e) {
            $message = $e->getMessage();
            $this->assertStringNotContainsString('DeployEcommerce', $message);
            $this->assertStringNotContainsString('Magento', $message);
            $this->assertStringNotContainsString('/', $message);
        }
    }

    private function processingParams(): DataObject
    {
        return new DataObject(['files_prefix' => 'item_1_']);
    }

    private function option(): Option
    {
        $option = $this->getMockBuilder(Option::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $option->method('getId')->willReturn(7);

        return $option;
    }

    /**
     * @param array<string, bool> $switches
     */
    private function plugin(array $switches, bool $uploaded = false): DenyFileOptionBackstop
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path) => $switches[$path] ?? null
        );

        $denialLogger = new DenialLogger(
            $this->createMock(LoggerInterface::class),
            $this->createMock(RemoteAddress::class)
        );

        $transfer = $this->createMock(FileTransfer::class);
        $transfer->method('isUploaded')->willReturn($uploaded);

        $httpFactory = $this->createMock(FileTransferFactory::class);
        $httpFactory->method('create')->willReturn($transfer);

        return new DenyFileOptionBackstop(
            new SwitchConfig($deploymentConfig),
            $denialLogger,
            $httpFactory
        );
    }
}
