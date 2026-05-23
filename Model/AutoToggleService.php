<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\Store;
use Psr\Log\LoggerInterface;

/**
 * Decides whether any of a product's "stock-dependent" supplier slots
 * should be auto-toggled (active=no when stock empty; active=yes when
 * stock back), and writes the change to the product's attribute (v0.1.0).
 *
 * Re-entry guard: writing a slot-active attribute fires
 * `catalog_product_save_after`, which feeds the reprice observer. We
 * use a request-scoped flag to avoid the reprice observer re-triggering
 * us in a loop.
 */
class AutoToggleService
{
    /** Set during evaluate() to prevent the reprice observer re-entering us. */
    private bool $inProgress = false;

    public function __construct(
        private readonly Config $config,
        private readonly SlotResolver $slotResolver,
        private readonly StockTrigger $stockTrigger,
        private readonly ProductAction $productAction,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Evaluate every stock-dependent slot for the given product and toggle
     * its active attribute if the stock state warrants it.
     *
     * Idempotent — re-running on a product whose state is already correct
     * is a no-op (no writes, no audit row).
     */
    public function evaluate(int $productId, string $triggerSource, ?int $storeId = null): void
    {
        if ($this->inProgress) {
            return;
        }
        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        $slots = $this->config->getSlots($storeId);
        if (empty($slots)) {
            return;
        }

        $stockDependentNames = $this->normaliseNames($this->config->getStockDependentSupplierNames($storeId));
        if (empty($stockDependentNames)) {
            return; // no slots eligible for auto-toggle
        }

        // Load product once with every attribute any slot needs.
        $product = $this->loadProduct($productId, $slots);
        if ($product === null) {
            return;
        }

        $this->inProgress = true;
        try {
            foreach ($slots as $slot) {
                $supplierName = $this->slotResolver->resolveSupplierName($product, $slot);
                if ($supplierName === null) {
                    continue;
                }
                $normalised = strtolower(trim($supplierName));
                if (!isset($stockDependentNames[$normalised])) {
                    continue;
                }

                $currentActive = (int) $product->getData($slot->activeAttr) === 1;

                if ($currentActive && $this->stockTrigger->isStockExhausted($product, $slot, $storeId)) {
                    $this->writeActive($productId, $slot, 0, $product, $triggerSource,
                        sprintf('%s stock exhausted → auto-toggle off', $supplierName));
                    // refresh local product state so subsequent iterations see the write
                    $product->setData($slot->activeAttr, 0);
                } elseif (!$currentActive
                    && $this->config->isReverseToggleOnRestock($storeId)
                    && $this->stockTrigger->isStockRestored($product, $slot, $storeId)
                ) {
                    $this->writeActive($productId, $slot, 1, $product, $triggerSource,
                        sprintf('%s stock restored → auto-toggle back on', $supplierName));
                    $product->setData($slot->activeAttr, 1);
                }
            }
        } finally {
            $this->inProgress = false;
        }
    }

    private function writeActive(
        int $productId,
        Slot $slot,
        int $newValue,
        \Magento\Catalog\Api\Data\ProductInterface $product,
        string $triggerSource,
        string $message
    ): void {
        try {
            $this->productAction->updateAttributes(
                [$productId],
                [$slot->activeAttr => $newValue],
                Store::DEFAULT_STORE_ID
            );

            $this->auditLogger->log([
                'product_id'      => $productId,
                'sku'             => (string) $product->getSku(),
                'event_type'      => AuditLogger::EVENT_AUTO_TOGGLE,
                'trigger_source'  => $triggerSource,
                'new_active_slot' => $newValue === 1 ? $slot->label : null,
                'old_active_slot' => $newValue === 1 ? null : $slot->label,
                'message'         => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ETechFlow_SupplierAutoflow: auto-toggle write failed.',
                ['product_id' => $productId, 'slot' => $slot->label, 'exception' => $e->getMessage()]
            );
        }
    }

    /**
     * @param string[] $names
     * @return array<string, true>  case-insensitive lookup
     */
    private function normaliseNames(array $names): array
    {
        $map = [];
        foreach ($names as $n) {
            $key = strtolower(trim($n));
            if ($key !== '') {
                $map[$key] = true;
            }
        }
        return $map;
    }

    /**
     * @param Slot[] $slots
     */
    private function loadProduct(int $productId, array $slots): ?\Magento\Catalog\Api\Data\ProductInterface
    {
        $attrCodes = ['sku'];
        foreach ($slots as $slot) {
            foreach ($slot->attributeCodes() as $code) {
                $attrCodes[] = $code;
            }
        }
        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter([$productId]);
        foreach (array_unique($attrCodes) as $code) {
            $collection->addAttributeToSelect($code);
        }
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();
        return $item->getId() ? $item : null;
    }
}
