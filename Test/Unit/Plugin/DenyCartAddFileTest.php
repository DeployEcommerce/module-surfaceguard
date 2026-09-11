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

    /**
     * The regression this guards: PHP puts every file input on a submitted multipart form
     * into $_FILES, empty ones included, with UPLOAD_ERR_NO_FILE. The product view form is
     * multipart whenever the product has any option, so counting entries refused ordinary
     * purchases of any product offering an optional file option.
     *
     * @dataProvider noActualUploadProvider
     */
    public function testEmptyFileInputsAreNotTreatedAsUploads(array $files): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_CART_ADD_FILE => false]);

        $this->raw->expects($this->never())->method('setHttpResponseCode');

        $result = $plugin->aroundExecute(
            $this->controllerWithFiles(new \ArrayObject($files)),
            static fn () => 'core-result'
        );

        $this->assertSame('core-result', $result);
    }

    /**
     * @return array<string, array{0: array}>
     */
    public static function noActualUploadProvider(): array
    {
        return [
            'optional file option left empty' => [[
                'options_7_file' => self::emptyEntry(),
            ]],
            'two optional options, both empty' => [[
                'options_7_file' => self::emptyEntry(),
                'options_9_file' => self::emptyEntry(),
            ]],
            'nested bracketed input, empty' => [[
                'options' => [7 => self::emptyEntry()],
            ]],
            'deeply nested, empty' => [[
                'options' => ['custom' => [7 => self::emptyEntry()]],
            ]],
        ];
    }

    /**
     * @dataProvider actualUploadProvider
     */
    public function testRealUploadIsRefusedWithABare403(array $files): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_CART_ADD_FILE => false]);

        $this->raw->expects($this->once())->method('setHttpResponseCode')->with(403);
        $this->raw->expects($this->once())->method('setContents')->with('');

        $result = $plugin->aroundExecute(
            $this->controllerWithFiles(new \ArrayObject($files)),
            static function () {
                self::fail('The controller must not run once the upload has been denied.');
            }
        );

        $this->assertSame($this->raw, $result);
    }

    /**
     * @return array<string, array{0: array}>
     */
    public static function actualUploadProvider(): array
    {
        return [
            'flat input carrying a file' => [[
                'options_7_file' => self::uploadedEntry(),
            ]],
            'nested bracketed input carrying a file' => [[
                'options' => [7 => self::uploadedEntry()],
            ]],
            'one empty option alongside one real upload' => [[
                'options_7_file' => self::emptyEntry(),
                'options_9_file' => self::uploadedEntry(),
            ]],
            'file rejected by PHP for exceeding the size limit' => [[
                'options_7_file' => [
                    'name' => 'polyglot.gif',
                    'type' => 'image/gif',
                    'tmp_name' => '',
                    'error' => UPLOAD_ERR_INI_SIZE,
                    'size' => 0,
                ],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyEntry(): array
    {
        return ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
    }

    /**
     * @return array<string, mixed>
     */
    private static function uploadedEntry(): array
    {
        return [
            'name' => 'polyglot.gif',
            'type' => 'image/gif',
            'tmp_name' => '/tmp/phpAb12Cd',
            'error' => UPLOAD_ERR_OK,
            'size' => 2048,
        ];
    }

    public function testUploadProceedsWhenTheSwitchAllows(): void
    {
        $plugin = $this->plugin([SwitchConfig::UPLOAD_CART_ADD_FILE => true]);

        $result = $plugin->aroundExecute(
            $this->controllerWithFiles(new \ArrayObject(['options_7_file' => self::uploadedEntry()])),
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
