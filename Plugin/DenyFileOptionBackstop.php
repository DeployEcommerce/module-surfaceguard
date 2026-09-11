<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Plugin;

use DeployEcommerce\SurfaceGuard\Model\DenialLogger;
use DeployEcommerce\SurfaceGuard\Model\SwitchConfig;
use Magento\Catalog\Model\Product\Option\Type\File\ValidatorFile;
use Magento\Catalog\Model\Product\Option\Type\File\ValidatorInfo;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Adapter\FileTransferFactory;
use Throwable;

/**
 * The guarantee behind "off means neutered".
 *
 * ValidatorFile and ValidatorInfo are the two points every custom-option file upload
 * crosses before the file is moved into pub/media — the fresh upload path and the
 * re-materialize path respectively. A controller guard can be routed around; this cannot.
 * A future resolver, a third-party controller, or an entry point nobody has enumerated
 * still dies here.
 *
 * Layer-agnostic on purpose, and that has a documented consequence: with either
 * custom-option switch off, the admin-side custom-option file flow is denied too. A store
 * that has switched the feature off is not selling file-option products, so this is the
 * intended reading rather than an oversight — but it is stated in the README so nobody
 * debugs it as a bug.
 */
final class DenyFileOptionBackstop
{
    private const ENDPOINT = 'custom-option file upload';

    /**
     * @param SwitchConfig $switchConfig
     * @param DenialLogger $denialLogger
     * @param FileTransferFactory $httpFactory
     */
    public function __construct(
        private readonly SwitchConfig $switchConfig,
        private readonly DenialLogger $denialLogger,
        private readonly FileTransferFactory $httpFactory
    ) {
    }

    /**
     * Refuse validation, and therefore the upload, when either custom-option switch is off.
     *
     * @param ValidatorFile|ValidatorInfo $subject
     * @param callable $proceed
     * @param mixed $firstArgument
     * @param mixed $option
     * @return mixed
     * @throws LocalizedException
     */
    public function aroundValidate(
        ValidatorFile|ValidatorInfo $subject,
        callable $proceed,
        mixed $firstArgument,
        mixed $option
    ) {
        $switch = $this->deniedSwitch();

        if ($switch === null) {
            return $proceed($firstArgument, $option);
        }

        /*
         * Core calls ValidatorFile::validate() for an optional file option even when the
         * customer uploaded nothing, and throws Validator\Exception to say so. File.php
         * catches that specific type and lets the purchase continue with a null value.
         * A LocalizedException from here would instead land in File.php's LocalizedException
         * catch, which rethrows — turning "no file on an optional option" into a failed
         * add-to-cart for every customer. Deny only when a file is genuinely present.
         *
         * ValidatorInfo is not affected: File.php only reaches it when it already has file
         * info in hand, so there is no empty case to protect.
         */
        if ($subject instanceof ValidatorFile && !$this->hasUploadedFile($firstArgument, $option)) {
            return $proceed($firstArgument, $option);
        }

        $this->denialLogger->denied(self::ENDPOINT, $switch);

        throw new LocalizedException(__('This operation is not available.'));
    }

    /**
     * Whether a file was actually uploaded for this option, using core's own file key.
     *
     * Undeterminable cases resolve to "no upload" and defer to core. Blocking a legitimate
     * purchase is the worse failure, and a real upload is still refused by the controller
     * guards on the way in and by ValidatorInfo on the re-materialize path.
     *
     * @param mixed $processingParams
     * @param mixed $option
     * @return bool
     */
    private function hasUploadedFile(mixed $processingParams, mixed $option): bool
    {
        if (!$processingParams instanceof DataObject || !is_object($option)) {
            return false;
        }

        if (!method_exists($option, 'getId')) {
            return false;
        }

        try {
            $fileKey = $processingParams->getFilesPrefix() . 'options_' . $option->getId() . '_file';

            return (bool)$this->httpFactory->create()->isUploaded($fileKey);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The first custom-option switch found to be off, if any.
     *
     * @return string|null
     */
    private function deniedSwitch(): ?string
    {
        foreach ([SwitchConfig::UPLOAD_CART_ADD_FILE, SwitchConfig::UPLOAD_GUEST_CART_ITEMS_FILE] as $switch) {
            if ($this->switchConfig->isDenied($switch)) {
                return $switch;
            }
        }

        return null;
    }
}
