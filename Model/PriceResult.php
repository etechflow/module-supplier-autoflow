<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model;

/**
 * Immutable result struct from PricingEngine::compute() (v0.1.0).
 *
 * Tells the caller which Magento price attributes to write + (optional)
 * a human-readable note for the audit log.
 *
 *   `null` for an attribute means "don't touch it"
 *   `float` means "write this value"
 *
 * Skipped results carry a reason string — the caller logs it but doesn't
 * change the product's prices.
 */
final class PriceResult
{
    private function __construct(
        public readonly bool $skipped,
        public readonly ?float $price,
        public readonly ?float $specialPrice,
        public readonly ?string $note
    ) {
    }

    public static function priceOnly(float $price, ?string $note = null): self
    {
        return new self(skipped: false, price: $price, specialPrice: null, note: $note);
    }

    public static function specialOnly(float $special, ?string $note = null): self
    {
        return new self(skipped: false, price: null, specialPrice: $special, note: $note);
    }

    public static function priceAndSpecial(float $price, float $special, ?string $note = null): self
    {
        return new self(skipped: false, price: $price, specialPrice: $special, note: $note);
    }

    public static function skipped(string $reason): self
    {
        return new self(skipped: true, price: null, specialPrice: null, note: $reason);
    }

    public function hasChanges(): bool
    {
        return !$this->skipped && ($this->price !== null || $this->specialPrice !== null);
    }
}
