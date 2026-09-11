<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Model;

use Magento\Framework\App\DeploymentConfig;
use Throwable;

/**
 * Resolves the SurfaceGuard switches from app/etc/env.php.
 *
 * Deployment config only: there is no database read and no admin field, so nothing
 * an admin session can reach will flip a switch.
 *
 * Fail-safe by design. Only a strict boolean false denies; an absent key, null, true,
 * 0, '0', 'false' and '' all allow, and an unreadable deployment config allows. The
 * module can never take a storefront down through a typo or a missing key.
 */
final class SwitchConfig
{
    public const GRAPHQL_ENABLED = 'harden/graphql/enabled';
    public const UPLOAD_CART_ADD_FILE = 'harden/uploads/cart_add_file';
    public const UPLOAD_GUEST_CART_ITEMS_FILE = 'harden/uploads/guest_cart_items_file';
    public const UPLOAD_CUSTOMER_ADDRESS_FILE = 'harden/uploads/customer_address_file';
    public const UPLOAD_CUSTOMER_CUSTOM_ATTR_FILE = 'harden/uploads/customer_custom_attr_file';

    /**
     * Resolved switches, keyed by config path. Memoized for the life of the request.
     *
     * @var array<string, bool>
     */
    private array $resolved = [];

    /**
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Whether the guarded feature is allowed to run.
     *
     * @param string $path
     * @return bool
     */
    public function isAllowed(string $path): bool
    {
        if (!array_key_exists($path, $this->resolved)) {
            $this->resolved[$path] = $this->read($path);
        }

        return $this->resolved[$path];
    }

    /**
     * Whether the guarded feature has been explicitly switched off.
     *
     * @param string $path
     * @return bool
     */
    public function isDenied(string $path): bool
    {
        return !$this->isAllowed($path);
    }

    /**
     * Read one switch, treating anything but a strict false as allow.
     *
     * @param string $path
     * @return bool
     */
    private function read(string $path): bool
    {
        try {
            $value = $this->deploymentConfig->get($path);
        } catch (Throwable) {
            // An unreadable env.php must not deny traffic. Core behaviour wins.
            return true;
        }

        return $value !== false;
    }
}
