<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Plugin;

use DeployEcommerce\SurfaceGuard\Model\DenialLogger;
use DeployEcommerce\SurfaceGuard\Model\SwitchConfig;
use Magento\Checkout\Controller\Cart\Add;
use Magento\Framework\Controller\ResultFactory;

/**
 * Denies custom-option file uploads on the storefront add-to-cart controller.
 *
 * Surgical: an ordinary add-to-cart post carries no files and proceeds untouched.
 * Only a request that actually brings a file with it is refused.
 */
final class DenyCartAddFile
{
    private const ENDPOINT = 'checkout/cart/add';

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
     * Refuse the request with a bare 403 when it carries an upload and the switch is off.
     *
     * @param Add $subject
     * @param callable $proceed
     * @return mixed
     */
    public function aroundExecute(Add $subject, callable $proceed)
    {
        if ($this->switchConfig->isAllowed(SwitchConfig::UPLOAD_CART_ADD_FILE)) {
            return $proceed();
        }

        if (!$this->carriesFiles($subject)) {
            return $proceed();
        }

        $this->denialLogger->denied(self::ENDPOINT, SwitchConfig::UPLOAD_CART_ADD_FILE);

        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode(403);
        $result->setContents('');

        return $result;
    }

    /**
     * Whether the request brought any uploaded file at all.
     *
     * @param Add $subject
     * @return bool
     */
    private function carriesFiles(Add $subject): bool
    {
        $files = $subject->getRequest()->getFiles();

        if ($files === null) {
            return false;
        }

        if (is_array($files)) {
            return $files !== [];
        }

        if ($files instanceof \Countable) {
            return count($files) > 0;
        }

        // An unrecognised shape is treated as "no files", in keeping with the module's
        // fail-safe rule. The custom-option backstop is what catches the upload itself.
        return false;
    }
}
