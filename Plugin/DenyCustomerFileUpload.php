<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Plugin;

use DeployEcommerce\SurfaceGuard\Model\DenialLogger;
use DeployEcommerce\SurfaceGuard\Model\SwitchConfig;
use Magento\Customer\Api\AddressMetadataInterface;
use Magento\Customer\Api\CustomerMetadataInterface;
use Magento\Customer\Model\FileUploaderFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Backstop for every customer-entity and customer-address file upload.
 *
 * FileUploaderFactory is the one seam all of these cross: the open-source frontend and
 * adminhtml upload controllers, and the Commerce custom-attribute upload controllers,
 * which all construct their uploader through it. Guarding it here means the Commerce
 * routes are covered without this module ever naming a Commerce-only class, which would
 * fail di:compile on an open-source install.
 *
 * The entity type arrives as a plugin argument, which is what lets one guard serve two
 * switches: customer_address for the address attributes, customer for the customer ones.
 *
 * FileUploader is constructed only to upload, never to read an existing file, so denying
 * here refuses uploads without disturbing the rendering of files already on disk.
 */
final class DenyCustomerFileUpload
{
    private const ENDPOINT = 'customer file upload';

    /**
     * @param SwitchConfig $switchConfig
     * @param DenialLogger $denialLogger
     */
    public function __construct(
        private readonly SwitchConfig $switchConfig,
        private readonly DenialLogger $denialLogger
    ) {
    }

    /**
     * Refuse to build an uploader for an entity type whose switch is off.
     *
     * @param FileUploaderFactory $subject
     * @param callable $proceed
     * @param array $data
     * @return mixed
     * @throws LocalizedException
     */
    public function aroundCreate(FileUploaderFactory $subject, callable $proceed, array $data = [])
    {
        $switch = $this->switchFor($data);

        if ($switch === null || $this->switchConfig->isAllowed($switch)) {
            return $proceed($data);
        }

        $this->denialLogger->denied(self::ENDPOINT, $switch);

        throw new LocalizedException(__('This operation is not available.'));
    }

    /**
     * Map the uploader's entity type to the switch that governs it.
     *
     * @param array $data
     * @return string|null
     */
    private function switchFor(array $data): ?string
    {
        $entityTypeCode = $data['entityTypeCode'] ?? null;

        if (!is_string($entityTypeCode)) {
            return null;
        }

        return match ($entityTypeCode) {
            AddressMetadataInterface::ENTITY_TYPE_ADDRESS => SwitchConfig::UPLOAD_CUSTOMER_ADDRESS_FILE,
            CustomerMetadataInterface::ENTITY_TYPE_CUSTOMER => SwitchConfig::UPLOAD_CUSTOMER_CUSTOM_ATTR_FILE,
            default => null,
        };
    }
}
