<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model;

use ETechFlow\SupplierAutoflow\Model\LicenseValidator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Admin-config wrapper for ETechFlow_SupplierAutoflow (v0.1.0).
 *
 * Reads everything from `Stores → Configuration → eTechFlow → Supplier
 * Autoflow`. All settings are merchant-configurable — the module ships
 * with sane defaults (e.g. anchor_multiplier=1.40) but ZERO hardcoded
 * attribute codes or supplier names. The same module installs cleanly
 * on Keystation (2 slots, magento_qty trigger) and on a hypothetical
 * 5-supplier MSI shop.
 */
class Config
{
    // -------------------------------------------------------------------------
    // Stock trigger source modes
    // -------------------------------------------------------------------------

    public const STOCK_TRIGGER_MAGENTO_QTY     = 'magento_qty';
    public const STOCK_TRIGGER_MSI_DEFAULT     = 'msi_default';
    public const STOCK_TRIGGER_MSI_PER_SLOT    = 'msi_per_slot';
    public const STOCK_TRIGGER_PER_SLOT_QTY    = 'per_slot_qty_attr';
    public const STOCK_TRIGGER_DISABLED        = 'disabled';

    // -------------------------------------------------------------------------
    // Price-output modes
    // -------------------------------------------------------------------------

    public const OUTPUT_PRICE                    = 'price';
    public const OUTPUT_SPECIAL_PRICE            = 'special_price';
    public const OUTPUT_SPECIAL_PRICE_WITH_ANCHOR = 'special_price_with_anchor';

    // -------------------------------------------------------------------------
    // Rounding modes
    // -------------------------------------------------------------------------

    public const ROUND_2DP        = '2dp';
    public const ROUND_5P         = '5p';
    public const ROUND_10P        = '10p';
    public const ROUND_99P_END    = '99p_ending';
    public const ROUND_NONE       = 'none';

    // -------------------------------------------------------------------------
    // No-active-supplier fallback behaviours
    // -------------------------------------------------------------------------

    public const NO_ACTIVE_OUT_OF_STOCK     = 'out_of_stock';
    public const NO_ACTIVE_DISABLE_PRODUCT  = 'disable_product';
    public const NO_ACTIVE_LEAVE_WITH_WARN  = 'leave_with_warning';

    // -------------------------------------------------------------------------
    // XML paths
    // -------------------------------------------------------------------------

    private const XML_ENABLED                         = 'etechflow_supplierautoflow/general/enabled';
    private const XML_SLOT_PAIRS                      = 'etechflow_supplierautoflow/slots/pairs';
    private const XML_STOCK_DEPENDENT_NAMES           = 'etechflow_supplierautoflow/slots/stock_dependent_names';
    private const XML_STOCK_TRIGGER_SOURCE            = 'etechflow_supplierautoflow/stock/trigger_source';
    private const XML_OUTPUT_TARGET                   = 'etechflow_supplierautoflow/pricing/output_target';
    private const XML_ROUNDING                        = 'etechflow_supplierautoflow/pricing/rounding';
    private const XML_ANCHOR_MULTIPLIER               = 'etechflow_supplierautoflow/pricing/anchor_multiplier';
    private const XML_ANCHOR_ATTRIBUTE                = 'etechflow_supplierautoflow/pricing/anchor_attribute';
    private const XML_NO_ACTIVE_BEHAVIOR              = 'etechflow_supplierautoflow/fallback/no_active_supplier_behavior';
    private const XML_REVERSE_TOGGLE_ON_RESTOCK       = 'etechflow_supplierautoflow/fallback/reverse_toggle_on_restock';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LicenseValidator $licenseValidator
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        if (!$this->licenseValidator->isValid()) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Parse the multi-line slot config into Slot objects, preserving
     * the merchant's priority order (top of textarea = highest priority).
     *
     * @return Slot[]
     */
    public function getSlots(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_SLOT_PAIRS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($raw === '') {
            return [];
        }

        $slots = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $slot = Slot::fromConfigLine($trimmed);
            if ($slot !== null) {
                $slots[] = $slot;
            }
        }
        return $slots;
    }

    /**
     * Supplier names whose slots get the stock-driven auto-toggle behaviour.
     * Match is case-insensitive (whitespace trimmed).
     *
     * Example: ["Onlyda"] means "when the Onlyda slot's stock source hits 0,
     * flip that slot's active flag to no". Other slots (Auto Remote, etc.)
     * stay manual-only — the merchant decides when to disable them.
     *
     * Empty list = no slots auto-toggle (the module's repricing engine
     * still runs when a merchant manually flips a slot).
     *
     * @return string[]
     */
    public function getStockDependentSupplierNames(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_STOCK_DEPENDENT_NAMES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($raw === '') {
            return [];
        }

        $names = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $names[] = $trimmed;
        }
        return $names;
    }

    public function getStockTriggerSource(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_STOCK_TRIGGER_SOURCE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $valid = [
            self::STOCK_TRIGGER_MAGENTO_QTY,
            self::STOCK_TRIGGER_MSI_DEFAULT,
            self::STOCK_TRIGGER_MSI_PER_SLOT,
            self::STOCK_TRIGGER_PER_SLOT_QTY,
            self::STOCK_TRIGGER_DISABLED,
        ];
        return in_array($value, $valid, true) ? $value : self::STOCK_TRIGGER_MAGENTO_QTY;
    }

    public function getOutputTarget(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_OUTPUT_TARGET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $valid = [
            self::OUTPUT_PRICE,
            self::OUTPUT_SPECIAL_PRICE,
            self::OUTPUT_SPECIAL_PRICE_WITH_ANCHOR,
        ];
        return in_array($value, $valid, true) ? $value : self::OUTPUT_SPECIAL_PRICE_WITH_ANCHOR;
    }

    public function getRoundingMode(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_ROUNDING,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $valid = [
            self::ROUND_2DP,
            self::ROUND_5P,
            self::ROUND_10P,
            self::ROUND_99P_END,
            self::ROUND_NONE,
        ];
        return in_array($value, $valid, true) ? $value : self::ROUND_2DP;
    }

    public function getAnchorMultiplier(?int $storeId = null): float
    {
        $value = $this->scopeConfig->getValue(self::XML_ANCHOR_MULTIPLIER, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value === null || $value === '') {
            return 1.40;
        }
        $float = (float) $value;
        return $float > 0 ? $float : 1.40;
    }

    /**
     * Optional per-product attribute code that overrides the global anchor
     * multiplier on a per-product basis. Empty = always use global.
     */
    public function getAnchorAttribute(?int $storeId = null): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_ANCHOR_ATTRIBUTE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?? '');
    }

    public function getNoActiveSupplierBehavior(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_NO_ACTIVE_BEHAVIOR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $valid = [
            self::NO_ACTIVE_OUT_OF_STOCK,
            self::NO_ACTIVE_DISABLE_PRODUCT,
            self::NO_ACTIVE_LEAVE_WITH_WARN,
        ];
        return in_array($value, $valid, true) ? $value : self::NO_ACTIVE_OUT_OF_STOCK;
    }

    public function isReverseToggleOnRestock(?int $storeId = null): bool
    {
        $value = $this->scopeConfig->getValue(
            self::XML_REVERSE_TOGGLE_ON_RESTOCK,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($value === null || $value === '') {
            return true;
        }
        return (bool) $value;
    }
}
