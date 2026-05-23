<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Framework\App\CacheInterface;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * Walks a product's slots, finds the first-active one, asks PricingEngine
 * for the computed price, writes back via ProductAction (v0.1.0).
 *
 * Re-entry guarded — same pattern as AutoToggleService — to avoid the
 * product-save observer triggering us in a loop after our write.
 *
 * Plays nicely with NDE: after writing price/special_price, fires
 * NdeIntegration::recomputeFor() synchronously to keep next_day_eligible
 * in sync with the new active supplier.
 */
class RepriceService
{
    private bool $inProgress = false;

    public function __construct(
        private readonly Config $config,
        private readonly SlotResolver $slotResolver,
        private readonly PricingEngine $pricingEngine,
        private readonly ProductAction $productAction,
        private readonly CacheInterface $cache,
        private readonly NdeIntegration $ndeIntegration,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger
    ) {
    }

    public function recompute(int $productId, string $triggerSource, ?int $storeId = null): void
    {
        if ($this->inProgress) {
            return;
        }
        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        $resolved = $this->slotResolver->resolve($productId, $storeId);
        if ($resolved === null) {
            $this->handleNoActiveSupplier($productId, $triggerSource, $storeId);
            return;
        }

        $slot    = $resolved['slot'];
        $product = $resolved['product'];

        $result = $this->pricingEngine->compute($product, $slot, $storeId);
        if ($result->skipped) {
            $this->logger->warning(
                'ETechFlow_SupplierAutoflow: pricing skipped — ' . ($result->note ?? 'no reason'),
                ['product_id' => $productId, 'slot' => $slot->label]
            );
            return;
        }

        $oldPrice = (float) $product->getData('price');
        $oldSpecial = $product->getData('special_price') !== null && $product->getData('special_price') !== ''
            ? (float) $product->getData('special_price')
            : null;

        $writes = [];
        if ($result->price !== null && (abs($oldPrice - $result->price) > 0.0001)) {
            $writes['price'] = $result->price;
        }
        if ($result->specialPrice !== null
            && ($oldSpecial === null || abs($oldSpecial - $result->specialPrice) > 0.0001)
        ) {
            $writes['special_price'] = $result->specialPrice;
        }

        if (empty($writes)) {
            return; // already in sync
        }

        $this->inProgress = true;
        try {
            $this->productAction->updateAttributes(
                [$productId],
                $writes,
                Store::DEFAULT_STORE_ID
            );

            // Invalidate FPC tag (mirrors NDE v1.6.2's pattern — necessary because
            // updateAttributes doesn't reliably fire the FPC-invalidation observer).
            $this->cache->clean([Product::CACHE_TAG . '_' . $productId]);

            $this->auditLogger->log([
                'product_id'        => $productId,
                'sku'               => (string) $product->getSku(),
                'event_type'        => AuditLogger::EVENT_REPRICE,
                'trigger_source'    => $triggerSource,
                'new_active_slot'   => $slot->label,
                'old_price'         => $oldPrice,
                'new_price'         => $writes['price'] ?? $oldPrice,
                'old_special_price' => $oldSpecial,
                'new_special_price' => $writes['special_price'] ?? $oldSpecial,
                'message'           => $result->note,
            ]);

            // Synchronously re-evaluate NDE eligibility based on the new
            // active supplier. No-op if NDE isn't installed.
            $this->ndeIntegration->recomputeFor($productId);

        } catch (\Throwable $e) {
            $this->logger->error(
                'ETechFlow_SupplierAutoflow: reprice write failed.',
                ['product_id' => $productId, 'exception' => $e->getMessage()]
            );
        } finally {
            $this->inProgress = false;
        }
    }

    /**
     * Apply the configured no-active-supplier fallback policy.
     */
    private function handleNoActiveSupplier(int $productId, string $triggerSource, ?int $storeId): void
    {
        $behavior = $this->config->getNoActiveSupplierBehavior($storeId);

        $this->inProgress = true;
        try {
            switch ($behavior) {
                case Config::NO_ACTIVE_OUT_OF_STOCK:
                    // Set is_in_stock = 0 + qty stays as-is. Storefront treats
                    // the product as out of stock.
                    $this->productAction->updateAttributes(
                        [$productId],
                        ['is_in_stock' => 0],
                        Store::DEFAULT_STORE_ID
                    );
                    $this->cache->clean([Product::CACHE_TAG . '_' . $productId]);
                    $this->auditLogger->log([
                        'product_id'     => $productId,
                        'event_type'     => AuditLogger::EVENT_NO_ACTIVE_SUPPLIER,
                        'trigger_source' => $triggerSource,
                        'message'        => 'No active supplier — set is_in_stock = 0',
                    ]);
                    break;

                case Config::NO_ACTIVE_DISABLE_PRODUCT:
                    $this->productAction->updateAttributes(
                        [$productId],
                        ['status' => 2], // Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED
                        Store::DEFAULT_STORE_ID
                    );
                    $this->cache->clean([Product::CACHE_TAG . '_' . $productId]);
                    $this->auditLogger->log([
                        'product_id'     => $productId,
                        'event_type'     => AuditLogger::EVENT_NO_ACTIVE_SUPPLIER,
                        'trigger_source' => $triggerSource,
                        'message'        => 'No active supplier — disabled product',
                    ]);
                    break;

                case Config::NO_ACTIVE_LEAVE_WITH_WARN:
                default:
                    $this->auditLogger->log([
                        'product_id'     => $productId,
                        'event_type'     => AuditLogger::EVENT_NO_ACTIVE_SUPPLIER,
                        'trigger_source' => $triggerSource,
                        'message'        => 'No active supplier — left unchanged per merchant config',
                    ]);
                    $this->logger->warning(
                        'ETechFlow_SupplierAutoflow: product has no active supplier slot; leaving unchanged.',
                        ['product_id' => $productId]
                    );
                    break;
            }
            $this->ndeIntegration->recomputeFor($productId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ETechFlow_SupplierAutoflow: no-active-supplier fallback failed.',
                ['product_id' => $productId, 'exception' => $e->getMessage()]
            );
        } finally {
            $this->inProgress = false;
        }
    }
}
