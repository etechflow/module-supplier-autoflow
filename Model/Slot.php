<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model;

/**
 * Immutable struct describing one configured supplier slot (v0.1.0).
 *
 * Holds the merchant-configured attribute codes (which product attributes
 * carry this slot's active flag, supplier name, cost, markup) and an
 * optional `stockSourceCode` for MSI-per-slot mode.
 *
 * "Priority" is the slot's position in the merchant's admin list — lower
 * index = higher priority = checked first by SlotResolver. The slot
 * itself doesn't know its own priority; that's tracked by the order
 * SlotResolver iterates.
 */
final class Slot
{
    public function __construct(
        public readonly string $label,
        public readonly string $activeAttr,
        public readonly string $nameAttr,
        public readonly string $costAttr,
        public readonly string $markupAttr,
        public readonly ?string $stockSourceCode = null,
        public readonly ?string $qtyAttr = null
    ) {
    }

    /**
     * Parse one config line from the admin "Supplier Slots" textarea.
     * Format:
     *   <label>|<active_attr>|<name_attr>|<cost_attr>|<markup_attr>[|<stock_source_or_qty_attr>]
     *
     * Lines starting with `#` or empty lines are skipped (caller filters).
     *
     * @return self|null  null when the line is malformed (caller logs)
     */
    public static function fromConfigLine(string $line): ?self
    {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 5) {
            return null;
        }
        [$label, $activeAttr, $nameAttr, $costAttr, $markupAttr] = $parts;
        if ($label === '' || $activeAttr === '' || $nameAttr === ''
            || $costAttr === '' || $markupAttr === '') {
            return null;
        }
        $sixth = isset($parts[5]) && $parts[5] !== '' ? $parts[5] : null;

        return new self(
            label:           $label,
            activeAttr:      $activeAttr,
            nameAttr:        $nameAttr,
            costAttr:        $costAttr,
            markupAttr:      $markupAttr,
            stockSourceCode: $sixth,
            qtyAttr:         $sixth  // dual-use: caller picks which to read based on stock trigger mode
        );
    }

    /**
     * Attribute codes this slot reads — used by SlotResolver to build a
     * single product-collection select.
     *
     * @return string[]
     */
    public function attributeCodes(): array
    {
        $codes = [$this->activeAttr, $this->nameAttr, $this->costAttr, $this->markupAttr];
        if ($this->qtyAttr !== null) {
            $codes[] = $this->qtyAttr;
        }
        return $codes;
    }
}
