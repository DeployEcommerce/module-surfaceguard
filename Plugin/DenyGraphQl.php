<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Plugin;

use DeployEcommerce\SurfaceGuard\Model\DenialLogger;
use DeployEcommerce\SurfaceGuard\Model\SwitchConfig;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;

/**
 * Whole-endpoint kill switch for POST /graphql.
 *
 * Blunt by design. Core GraphQL cart mutations take string-only option inputs and carry
 * no file-write sink, so there is no upload variant to deny selectively; the only lever
 * is the endpoint itself. Switching this off stops every headless and PWA storefront
 * call, so it belongs only on sites confirmed to be Luma or Hyvä with no GraphQL
 * consumers.
 *
 * Declared on the front controller interface in the graphql area, ahead of the cache
 * plugins — see etc/graphql/di.xml for why the sort order matters.
 */
final class DenyGraphQl
{
    private const ENDPOINT = 'graphql';

    /**
     * @param SwitchConfig $switchConfig
     * @param DenialLogger $denialLogger
     * @param HttpResponse $response
     */
    public function __construct(
        private readonly SwitchConfig $switchConfig,
        private readonly DenialLogger $denialLogger,
        private readonly HttpResponse $response
    ) {
    }

    /**
     * Refuse every GraphQL request with a bare 403 when the switch is off.
     *
     * @param FrontControllerInterface $subject
     * @param callable $proceed
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    public function aroundDispatch(
        FrontControllerInterface $subject,
        callable $proceed,
        RequestInterface $request
    ): ResponseInterface {
        if ($this->switchConfig->isAllowed(SwitchConfig::GRAPHQL_ENABLED)) {
            return $proceed($request);
        }

        $this->denialLogger->denied(self::ENDPOINT, SwitchConfig::GRAPHQL_ENABLED);

        $this->response->setHttpResponseCode(403);
        $this->response->setBody('');

        return $this->response;
    }
}
