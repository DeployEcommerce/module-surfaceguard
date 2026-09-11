<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Test\Unit\Plugin;

use DeployEcommerce\SurfaceGuard\Model\DenialLogger;
use DeployEcommerce\SurfaceGuard\Model\SwitchConfig;
use DeployEcommerce\SurfaceGuard\Plugin\DenyCartAddFile;
use Magento\Checkout\Controller\Cart\Add;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SG-4: a plain add-to-cart keeps working while the switch is off. Only a request that
 * actually carries a file is refused.
 */
class DenyCartAddFileTest extends TestCase
{
    private ResultFactory&MockObject $resultFactory;

    private Raw&MockObject $raw;

    protected function setUp(): void
    {
        $this->raw = $this->createMock(Raw::class);
        $this->resultFactory = $this->createMock(ResultFactory::class);
        $this->resultFactory->method('create')->willReturn($this->raw);
    }

    public function testPlainAddToCartProceedsWhileTheSwitchIsOff(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_CART_ADD_FILE => false]);

        $result = $plugin->aroundExecute(
            $this->controllerWithFiles(new \ArrayObject([])),
            static fn () => 'core-result'
        );

        $this->assertSame('core-result', $result);
    }

    public function testUploadIsRefusedWithABare403(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_CART_ADD_FILE => false]);

        $this->raw->expects($this->once())->method('setHttpResponseCode')->with(403);
        $this->raw->expects($this->once())->method('setContents')->with('');

        $result = $plugin->aroundExecute(
            $this->controllerWithFiles(new \ArrayObject(['options' => ['tmp_name' => 'x']])),
            static function () {
                self::fail('The controller must not run once the upload has been denied.');
            }
        );

        $this->assertSame($this->raw, $result);
    }

    public function testUploadProceedsWhenTheSwitchAllows(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_CART_ADD_FILE => true]);

        $result = $plugin->aroundExecute(
            $this->controllerWithFiles(new \ArrayObject(['options' => ['tmp_name' => 'x']])),
            static fn () => 'core-result'
        );

        $this->assertSame('core-result', $result);
    }

    private function controllerWithFiles(mixed $files): Add&MockObject
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getFiles')->willReturn($files);

        $controller = $this->getMockBuilder(Add::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRequest'])
            ->getMock();
        $controller->method('getRequest')->willReturn($request);

        return $controller;
    }

    /**
     * @param array<string, bool> $switches
     */
    private function plugin(array $switches): DenyCartAddFile
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path) => $switches[$path] ?? null
        );

        $denialLogger = new DenialLogger(
            $this->createMock(LoggerInterface::class),
            $this->createMock(RemoteAddress::class)
        );

        return new DenyCartAddFile(
            new SwitchConfig($deploymentConfig),
            $denialLogger,
            $this->resultFactory
        );
    }
}
