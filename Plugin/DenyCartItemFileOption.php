<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 */

declare(strict_types=1);

namespace DeployEcommerce\SurfaceGuard\Plugin;

use DeployEcommerce\SurfaceGuard\Model\DenialLogger;
use DeployEcommerce\SurfaceGuard\Model\SwitchConfig;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterface;

/**
 * Denies file-type custom options on REST cart-item saves.
 *
 * Declared on the service contract rather than a concrete repository, so it covers guest
 * carts, carts/mine, and anything else that saves through the interface.
 *
 * Keyed on the option rather than the route: a plain item add passes untouched, which is
 * what keeps ordinary REST integrations working while the file path stays shut.
 *
 * Detection reads the option's structure. The REST re-materialize path is recognised by the
 * quote_path / order_path / secret_key keys that ValidatorInfo itself looks for, carried in
 * a keyed map rather than found as a substring, so a text option whose value mentions one of
 * those words is not mistaken for an upload. Reading the keys avoids loading product option
 * metadata on every cart write, and the ValidatorInfo backstop is what guarantees the denial
 * if a payload shape ever slips past them.
 */
final class DenyCartItemFileOption
{
    private const ENDPOINT = 'rest/V1/carts/items';

    private const FILE_OPTION_MARKERS = ['quote_path', 'order_path', 'secret_key'];

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
     * Refuse the save with a 403 when the item carries a file option and the switch is off.
     *
     * @param CartItemRepositoryInterface $subject
     * @param callable $proceed
     * @param CartItemInterface $cartItem
     * @return CartItemInterface
     * @throws WebapiException
     */
    public function aroundSave(
        CartItemRepositoryInterface $subject,
        callable $proceed,
        CartItemInterface $cartItem
    ): CartItemInterface {
        if ($this->switchConfig->isAllowed(SwitchConfig::UPLOAD_GUEST_CART_ITEMS_FILE)) {
            return $proceed($cartItem);
        }

        if (!$this->carriesFileOption($cartItem)) {
            return $proceed($cartItem);
        }

        $this->denialLogger->denied(self::ENDPOINT, SwitchConfig::UPLOAD_GUEST_CART_ITEMS_FILE);

        throw new WebapiException(
            __('This operation is not available.'),
            0,
            WebapiException::HTTP_FORBIDDEN
        );
    }

    /**
     * Whether any custom option on the item looks like a file re-materialize payload.
     *
     * @param CartItemInterface $cartItem
     * @return bool
     */
    private function carriesFileOption(CartItemInterface $cartItem): bool
    {
        $productOption = $cartItem->getProductOption();
        if ($productOption === null) {
            return false;
        }

        $extensionAttributes = $productOption->getExtensionAttributes();
        if ($extensionAttributes === null || !method_exists($extensionAttributes, 'getCustomOptions')) {
            return false;
        }

        $customOptions = $extensionAttributes->getCustomOptions();
        if (!is_array($customOptions)) {
            return false;
        }

        foreach ($customOptions as $customOption) {
            if (!method_exists($customOption, 'getOptionValue')) {
                continue;
            }

            if ($this->looksLikeFileValue($customOption->getOptionValue())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one option value carries the structure of a file option.
     *
     * Structure, never substring. A file option's value is a keyed map — an array, or the
     * JSON encoding of one. A text option's value is the customer's own prose, and matching
     * substrings inside it would refuse an ordinary purchase for anyone who happens to type
     * "print secret_key on the label".
     *
     * Only JSON is decoded. Attacker-controlled input is never unserialized.
     *
     * @param mixed $value
     * @return bool
     */
    private function looksLikeFileValue(mixed $value): bool
    {
        if (is_array($value)) {
            return $this->hasFileKeys($value);
        }

        if (!is_string($value) || $value === '') {
            return false;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && $this->hasFileKeys($decoded);
    }

    /**
     * Whether a decoded option value carries any of the file metadata keys.
     *
     * @param array $value
     * @return bool
     */
    private function hasFileKeys(array $value): bool
    {
        foreach (self::FILE_OPTION_MARKERS as $marker) {
            if (array_key_exists($marker, $value)) {
                return true;
            }
        }

        return false;
    }
}
