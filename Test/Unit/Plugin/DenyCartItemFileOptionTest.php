<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Test\Unit\Plugin;

use DeployEcommerce\SurfaceGuard\Model\DenialLogger;
use DeployEcommerce\SurfaceGuard\Model\SwitchConfig;
use DeployEcommerce\SurfaceGuard\Plugin\DenyCartItemFileOption;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Api\Data\ProductOptionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The guard is keyed on the option, not the route, so a plain REST item add is untouched.
 */
class DenyCartItemFileOptionTest extends TestCase
{
    public function testPlainItemAddPasses(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_GUEST_CART_ITEMS_FILE => false]);
        $cartItem = $this->cartItem(null);

        $result = $plugin->aroundSave(
            $this->createMock(CartItemRepositoryInterface::class),
            static fn (CartItemInterface $item) => $item,
            $cartItem
        );

        $this->assertSame($cartItem, $result);
    }

    public function testFileOptionIsRefusedWith403(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_GUEST_CART_ITEMS_FILE => false]);

        try {
            $plugin->aroundSave(
                $this->createMock(CartItemRepositoryInterface::class),
                static function () {
                    self::fail('The repository must not save once the item has been denied.');
                },
                $this->cartItem(['quote_path' => 'custom_options/quote/s/p/spec.gif'])
            );
            $this->fail('Expected the file option to be denied.');
        } catch (WebapiException $e) {
            $this->assertSame(403, $e->getHttpCode());
            $this->assertStringNotContainsString('quote_path', $e->getMessage());
        }
    }

    public function testSerializedFileOptionIsRecognised(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_GUEST_CART_ITEMS_FILE => false]);

        $this->expectException(WebapiException::class);

        $plugin->aroundSave(
            $this->createMock(CartItemRepositoryInterface::class),
            static fn (CartItemInterface $item) => $item,
            $this->cartItem('{"quote_path":"custom_options\/quote\/s\/p\/spec.gif"}')
        );
    }

    public function testFileOptionPassesWhenTheSwitchAllows(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_GUEST_CART_ITEMS_FILE => true]);
        $cartItem = $this->cartItem(['quote_path' => 'anything']);

        $this->assertSame(
            $cartItem,
            $plugin->aroundSave(
                $this->createMock(CartItemRepositoryInterface::class),
                static fn (CartItemInterface $item) => $item,
                $cartItem
            )
        );
    }

    private function cartItem(mixed $optionValue): CartItemInterface
    {
        $cartItem = $this->createMock(CartItemInterface::class);

        if ($optionValue === null) {
            $cartItem->method('getProductOption')->willReturn(null);

            return $cartItem;
        }

        $customOption = new class ($optionValue) {
            public function __construct(private readonly mixed $value)
            {
            }

            public function getOptionValue(): mixed
            {
                return $this->value;
            }
        };

        $extensionAttributes = new class ([$customOption]) {
            /**
             * @param array<int, object> $options
             */
            public function __construct(private readonly array $options)
            {
            }

            /**
             * @return array<int, object>
             */
            public function getCustomOptions(): array
            {
                return $this->options;
            }
        };

        $productOption = $this->createMock(ProductOptionInterface::class);
        $productOption->method('getExtensionAttributes')->willReturn($extensionAttributes);
        $cartItem->method('getProductOption')->willReturn($productOption);

        return $cartItem;
    }

    /**
     * @param array<string, bool> $switches
     */
    private function plugin(array $switches): DenyCartItemFileOption
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path) => $switches[$path] ?? null
        );

        $denialLogger = new DenialLogger(
            $this->createMock(LoggerInterface::class),
            $this->createMock(RemoteAddress::class)
        );

        return new DenyCartItemFileOption(new SwitchConfig($deploymentConfig), $denialLogger);
    }
}
