<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Test\Unit\Plugin;

use DeployEcommerce\SurfaceGuard\Model\DenialLogger;
use DeployEcommerce\SurfaceGuard\Model\SwitchConfig;
use DeployEcommerce\SurfaceGuard\Plugin\DenyFileOptionBackstop;
use Magento\Catalog\Model\Product\Option\Type\File\ValidatorFile;
use Magento\Catalog\Model\Product\Option\Type\File\ValidatorInfo;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SG-5: the backstop denies a file-option upload even when no controller guard fires.
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
            ['file' => 'payload'],
            'option'
        );

        $this->assertTrue($called, 'The original validator must run when nothing is switched off.');
        $this->assertSame(['validated'], $result);
    }

    public function testDeniesWhenCartAddSwitchIsOff(): void
    {
        $plugin = $this->plugin([
            SwitchConfig::UPLOAD_CART_ADD_FILE => false,
            SwitchConfig::UPLOAD_GUEST_CART_ITEMS_FILE => true,
        ]);

        $proceed = static function () {
            self::fail('The original validator must not run once a switch has denied.');
        };

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This operation is not available.');

        $plugin->aroundValidate($this->createMock(ValidatorFile::class), $proceed, [], 'option');
    }

    public function testDeniesTheRestReMaterializePathToo(): void
    {
        $plugin = $this->plugin([
            SwitchConfig::UPLOAD_CART_ADD_FILE => true,
            SwitchConfig::UPLOAD_GUEST_CART_ITEMS_FILE => false,
        ]);

        $proceed = static function () {
            self::fail('The original validator must not run once a switch has denied.');
        };

        $this->expectException(LocalizedException::class);

        $plugin->aroundValidate($this->createMock(ValidatorInfo::class), $proceed, [], 'option');
    }

    public function testDenialMessageLeaksNothingInternal(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_CART_ADD_FILE => false]);

        try {
            $plugin->aroundValidate(
                $this->createMock(ValidatorFile::class),
                static fn () => null,
                [],
                'option'
            );
            $this->fail('Expected the backstop to deny.');
        } catch (LocalizedException $e) {
            $message = $e->getMessage();
            $this->assertStringNotContainsString('DeployEcommerce', $message);
            $this->assertStringNotContainsString('Magento', $message);
            $this->assertStringNotContainsString('/', $message);
        }
    }

    /**
     * @param array<string, bool> $switches
     */
    private function plugin(array $switches): DenyFileOptionBackstop
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path) => $switches[$path] ?? null
        );

        $denialLogger = new DenialLogger(
            $this->createMock(LoggerInterface::class),
            $this->createMock(RemoteAddress::class)
        );

        return new DenyFileOptionBackstop(new SwitchConfig($deploymentConfig), $denialLogger);
    }
}
