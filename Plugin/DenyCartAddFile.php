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

        if (!$this->carriesUpload($subject)) {
            return $proceed();
        }

        $this->denialLogger->denied(self::ENDPOINT, SwitchConfig::UPLOAD_CART_ADD_FILE);

        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode(403);
        $result->setContents('');

        return $result;
    }

    /**
     * Whether the request actually carries an uploaded file.
     *
     * The presence of entries is not proof of an upload. PHP puts every file input on a
     * submitted multipart form into $_FILES, including ones the customer left empty, with
     * error UPLOAD_ERR_NO_FILE — and Laminas keeps them all when it maps the superglobal.
     * The product view form is multipart whenever the product has any option at all, so
     * counting entries would refuse ordinary purchases of any product that merely offers an
     * optional file option.
     *
     * @param Add $subject
     * @return bool
     */
    private function carriesUpload(Add $subject): bool
    {
        $files = $subject->getRequest()->getFiles();

        if ($files instanceof \ArrayObject) {
            $files = $files->getArrayCopy();
        } elseif ($files instanceof \Traversable) {
            $files = iterator_to_array($files);
        }

        if (!is_array($files)) {
            // An unrecognised shape is treated as "no upload", in keeping with the module's
            // fail-safe rule. The custom-option backstop still catches the upload itself.
            return false;
        }

        return $this->containsUpload($files);
    }

    /**
     * Walk the mapped file parameters, which nest when an input name carries brackets.
     *
     * @param array $entries
     * @return bool
     */
    private function containsUpload(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ($this->isUpload($entry) || $this->containsUpload($entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one mapped entry represents a file the customer actually submitted.
     *
     * Any error other than UPLOAD_ERR_NO_FILE still means a file was sent, even when PHP
     * rejected it and left the size at zero, so those count as an upload and are denied.
     *
     * @param array $entry
     * @return bool
     */
    private function isUpload(array $entry): bool
    {
        if (!array_key_exists('error', $entry) || is_array($entry['error'])) {
            return false;
        }

        return (int)$entry['error'] !== UPLOAD_ERR_NO_FILE;
    }
}
