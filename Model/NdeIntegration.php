<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model;

use Magento\Framework\App\ObjectManager;
use Psr\Log\LoggerInterface;

/**
 * Soft-detect ETechFlow_NextDayEligibility and synchronously trigger its
 * eligibility recompute when a product's slot active flag or price changes
 * (v0.1.0).
 *
 * Why explicit invocation instead of relying on the event chain:
 *
 *   Autoflow writes the slot's active flag + the product's price via
 *   ProductAction::updateAttributes, which fires
 *   `catalog_product_attribute_update_after`. NDE's existing observer
 *   listens for `cataloginventory_stock_item_save_after` and
 *   `catalog_product_save_after` — NEITHER of which fires for
 *   updateAttributes() (different event signature).
 *
 *   So the event-chain approach silently fails to update NDE eligibility
 *   when Autoflow flips a supplier. Direct synchronous invocation is the
 *   only reliable path.
 *
 * Soft-detect via class_exists keeps Autoflow installable + functional
 * on stores without NDE — the integration just no-ops.
 */
class NdeIntegration
{
    private ?bool $available = null;
    private ?object $evaluator = null;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        return $this->available = class_exists(
            '\\ETechFlow\\NextDayEligibility\\Model\\EligibilityEvaluator',
            true
        );
    }

    /**
     * Recompute NDE eligibility for the given product. No-op when NDE
     * isn't installed.
     */
    public function recomputeFor(int $productId): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        try {
            if ($this->evaluator === null) {
                $this->evaluator = ObjectManager::getInstance()->get(
                    '\\ETechFlow\\NextDayEligibility\\Model\\EligibilityEvaluator'
                );
            }
            $this->evaluator->evaluateById($productId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_SupplierAutoflow: NDE eligibility recompute failed (NDE installed but errored).',
                ['product_id' => $productId, 'exception' => $e->getMessage()]
            );
        }
    }
}
