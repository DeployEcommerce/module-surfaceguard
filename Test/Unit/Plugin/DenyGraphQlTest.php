<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Test\Unit\Plugin;

use DeployEcommerce\SurfaceGuard\Model\DenialLogger;
use DeployEcommerce\SurfaceGuard\Model\SwitchConfig;
use DeployEcommerce\SurfaceGuard\Plugin\DenyGraphQl;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SG-6: the endpoint is refused outright when switched off, and untouched otherwise.
 */
class DenyGraphQlTest extends TestCase
{
    private HttpResponse&MockObject $response;

    protected function setUp(): void
    {
        $this->response = $this->createMock(HttpResponse::class);
    }

    public function testRequestIsRefusedWhenSwitchedOff(): void
    {
        $plugin = $this->plugin([SwitchConfig::GRAPHQL_ENABLED => false]);

        $this->response->expects($this->once())->method('setHttpResponseCode')->with(403);
        $this->response->expects($this->once())->method('setBody')->with('');

        $result = $plugin->aroundDispatch(
            $this->controller(),
            static function () {
                self::fail('The controller must not run once GraphQL has been switched off.');
            },
            $this->createMock(RequestInterface::class)
        );

        $this->assertSame($this->response, $result);
    }

    public function testRequestProceedsWhenTheKeyIsAbsent(): void
    {
        $plugin = $this->plugin([]);

        $expected = $this->createMock(HttpResponse::class);
        $request = $this->createMock(RequestInterface::class);

        $this->response->expects($this->never())->method('setHttpResponseCode');

        $result = $plugin->aroundDispatch(
            $this->controller(),
            static fn (RequestInterface $passed) => $expected,
            $request
        );

        $this->assertSame($expected, $result);
    }

    private function controller(): FrontControllerInterface&MockObject
    {
        return $this->createMock(FrontControllerInterface::class);
    }

    /**
     * @param array<string, bool> $switches
     */
    private function plugin(array $switches): DenyGraphQl
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path) => $switches[$path] ?? null
        );

        $denialLogger = new DenialLogger(
            $this->createMock(LoggerInterface::class),
            $this->createMock(RemoteAddress::class)
        );

        return new DenyGraphQl(new SwitchConfig($deploymentConfig), $denialLogger, $this->response);
    }
}
