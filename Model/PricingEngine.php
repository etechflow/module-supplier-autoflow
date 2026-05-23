<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Computes the customer-facing price + special_price for a product given
 * its first-active supplier slot (v0.1.0).
 *
 * Pure-function-ish: no side effects, no Magento save calls. Caller
 * (RepriceOnProductSave observer / ResyncCommand) takes the
 * `PriceResult` returned here and writes it back via ProductAction.
 */
class PricingEngine
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @return PriceResult
     */
    public function compute(ProductInterface $product, Slot $slot, ?int $storeId = null): PriceResult
    {
        $cost   = (float) $product->getData($slot->costAttr);
        $markup = (float) $product->getData($slot->markupAttr);

        // Defensive — without cost we can't price.
        if ($cost <= 0) {
            return PriceResult::skipped(
                "Cost attribute '{$slot->costAttr}' is missing or zero on product " . $product->getId()
            );
        }

        $rawSpecial = $cost * (1 + ($markup / 100));
        $rounding   = $this->config->getRoundingMode($storeId);
        $special    = $this->applyRounding($rawSpecial, $rounding);

        $target = $this->config->getOutputTarget($storeId);

        switch ($target) {
            case Config::OUTPUT_PRICE:
                return PriceResult::priceOnly($special);

            case Config::OUTPUT_SPECIAL_PRICE:
                return PriceResult::specialOnly($special);

            case Config::OUTPUT_SPECIAL_PRICE_WITH_ANCHOR:
                $anchor = $this->resolveAnchor($product, $storeId);
                $regularRaw = $special * $anchor;
                $regular    = $this->applyRounding($regularRaw, $rounding);

                if ($regular <= $special) {
                    // Anchor failed to land above special — skip the special_price
                    // write so the storefront doesn't show a negative or zero
                    // discount. Customer sees the regular price only.
                    return PriceResult::priceOnly(
                        $regular,
                        "anchor multiplier ({$anchor}) produced regular price " .
                        "({$regular}) <= special price ({$special}); falling back to regular-only"
                    );
                }
                return PriceResult::priceAndSpecial($regular, $special);

            default:
                return PriceResult::skipped("Unknown output target '{$target}'");
        }
    }

    private function resolveAnchor(ProductInterface $product, ?int $storeId): float
    {
        $attrCode = $this->config->getAnchorAttribute($storeId);
        if ($attrCode !== '') {
            $perProduct = $product->getData($attrCode);
            if ($perProduct !== null && $perProduct !== '' && (float) $perProduct > 0) {
                return (float) $perProduct;
            }
        }
        return $this->config->getAnchorMultiplier($storeId);
    }

    private function applyRounding(float $value, string $mode): float
    {
        switch ($mode) {
            case Config::ROUND_NONE:
                return $value;
            case Config::ROUND_5P:
                return round($value * 20) / 20;
            case Config::ROUND_10P:
                return round($value * 10) / 10;
            case Config::ROUND_99P_END:
                // Round down to nearest integer pound, then +0.99
                return floor($value) + 0.99;
            case Config::ROUND_2DP:
            default:
                return round($value, 2);
        }
    }
}
