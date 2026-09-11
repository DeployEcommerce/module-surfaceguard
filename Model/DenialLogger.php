<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Model;

use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes one line per denial to var/log/surfaceguard.log.
 *
 * Deliberately narrow: the endpoint label, the switch that denied it, and the client
 * IP. No filename, no request body, no header dump — a log that carries attacker-
 * supplied content is its own liability, and the request stream in mjolnir is the
 * place to go for the full picture.
 */
final class DenialLogger
{
    /**
     * @param LoggerInterface $logger
     * @param RemoteAddress $remoteAddress
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    /**
     * Record that an endpoint was denied by a switch.
     *
     * @param string $endpoint
     * @param string $switch
     * @return void
     */
    public function denied(string $endpoint, string $switch): void
    {
        try {
            $this->logger->warning(
                'SurfaceGuard denied an upload.',
                [
                    'endpoint' => $endpoint,
                    'switch' => $switch,
                    'client_ip' => (string)$this->remoteAddress->getRemoteAddress(),
                ]
            );
        } catch (Throwable) {
            // Logging must never be the reason a denial fails to deny.
        }
    }
}
