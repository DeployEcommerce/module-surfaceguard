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
use Magento\Framework\Exception\LocalizedException;

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
     */
    public function __construct(
        private readonly SwitchConfig $switchConfig,
        private readonly DenialLogger $denialLogger
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

        $this->denialLogger->denied(self::ENDPOINT, $switch);

        throw new LocalizedException(__('This operation is not available.'));
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
