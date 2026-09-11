<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Test\Unit\Model;

use DeployEcommerce\SurfaceGuard\Model\SwitchConfig;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\FileSystemException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * SG-2: only a strict boolean false denies. Everything else, including an unreadable
 * deployment config, resolves to core behaviour.
 */
class SwitchConfigTest extends TestCase
{
    private DeploymentConfig&MockObject $deploymentConfig;

    private SwitchConfig $switchConfig;

    protected function setUp(): void
    {
        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);
        $this->switchConfig = new SwitchConfig($this->deploymentConfig);
    }

    /**
     * @dataProvider allowingValuesProvider
     */
    public function testOnlyStrictFalseDenies(mixed $configured, bool $expectedAllowed): void
    {
        $this->deploymentConfig->method('get')->willReturn($configured);

        $this->assertSame(
            $expectedAllowed,
            $this->switchConfig->isAllowed(SwitchConfig::UPLOAD_CART_ADD_FILE)
        );
        $this->assertSame(
            !$expectedAllowed,
            $this->switchConfig->isDenied(SwitchConfig::UPLOAD_CART_ADD_FILE)
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function allowingValuesProvider(): array
    {
        return [
            'absent key returns null' => [null, true],
            'explicit true' => [true, true],
            'integer zero' => [0, true],
            'string zero' => ['0', true],
            'string false' => ['false', true],
            'empty string' => ['', true],
            'empty array' => [[], true],
            'strict boolean false' => [false, false],
        ];
    }

    public function testUnreadableDeploymentConfigAllows(): void
    {
        $this->deploymentConfig->method('get')
            ->willThrowException(new FileSystemException(__('env.php is unreadable.')));

        $this->assertTrue($this->switchConfig->isAllowed(SwitchConfig::GRAPHQL_ENABLED));
    }

    public function testEachSwitchIsReadOnlyOnce(): void
    {
        $this->deploymentConfig->expects($this->once())
            ->method('get')
            ->with(SwitchConfig::GRAPHQL_ENABLED)
            ->willReturn(false);

        $this->assertFalse($this->switchConfig->isAllowed(SwitchConfig::GRAPHQL_ENABLED));
        $this->assertFalse($this->switchConfig->isAllowed(SwitchConfig::GRAPHQL_ENABLED));
        $this->assertTrue($this->switchConfig->isDenied(SwitchConfig::GRAPHQL_ENABLED));
    }

    public function testSwitchesAreResolvedIndependently(): void
    {
        $this->deploymentConfig->method('get')
            ->willReturnMap([
                [SwitchConfig::UPLOAD_CART_ADD_FILE, null, false],
                [SwitchConfig::GRAPHQL_ENABLED, null, true],
            ]);

        $this->assertTrue($this->switchConfig->isDenied(SwitchConfig::UPLOAD_CART_ADD_FILE));
        $this->assertTrue($this->switchConfig->isAllowed(SwitchConfig::GRAPHQL_ENABLED));
    }
}
