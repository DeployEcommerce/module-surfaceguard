<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Plugin;

use DeployEcommerce\SurfaceGuard\Model\DenialLogger;
use DeployEcommerce\SurfaceGuard\Model\SwitchConfig;
use Magento\Customer\Controller\Address\File\Upload;
use Magento\Framework\Controller\ResultFactory;

/**
 * Denies the guest-reachable customer address file upload with a bare 403.
 *
 * This controller is the endpoint named in the surface audit (customer/address_file/upload)
 * and it is not login-gated, so it gets a guard of its own rather than relying only on the
 * shared uploader backstop — a controller guard is what lets the response be a flat 403
 * instead of the controller's own JSON error envelope.
 */
final class DenyCustomerAddressFileUpload
{
    private const ENDPOINT = 'customer/address_file/upload';

    /**
     * @param SwitchConfig $switchConfig
     * @param DenialLogger $denialLogger
     * @param ResultFactory $resultFactory
     */
    public function __construct(
        private readonly SwitchConfig $switchConfig,
        private readonly DenialLogger $denialLogger,
        private readonly ResultFactory $resultFactory
    ) {
    }

    /**
     * Refuse the upload outright when the switch is off.
     *
     * @param Upload $subject
     * @param callable $proceed
     * @return mixed
     */
    public function aroundExecute(Upload $subject, callable $proceed)
    {
        if ($this->switchConfig->isAllowed(SwitchConfig::UPLOAD_CUSTOMER_ADDRESS_FILE)) {
            return $proceed();
        }

        $this->denialLogger->denied(self::ENDPOINT, SwitchConfig::UPLOAD_CUSTOMER_ADDRESS_FILE);

        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode(403);
        $result->setContents('');

        return $result;
    }
}
