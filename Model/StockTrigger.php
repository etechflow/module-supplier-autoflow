<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\ObjectManager;
use Psr\Log\LoggerInterface;

/**
 * Resolves "is this slot's stock source empty?" based on the merchant's
 * configured stock-trigger mode (v0.1.0).
 *
 * Five modes, picked in admin:
 *
 *   - magento_qty         Read the product's standard StockRegistry qty.
 *                         (Legacy, works on every Magento install.)
 *
 *   - msi_default         Read MSI's "default" source qty for the product
 *                         via GetSourceItemsBySkuInterface.
 *
 *   - msi_per_slot        Read MSI's per-slot source code (slot.stockSourceCode)
 *                         qty for the product. Each supplier has its own MSI
 *                         source (e.g. source=onlyda, source=autoremote).
 *
 *   - per_slot_qty_attr   Read a per-slot product attribute (slot.qtyAttr)
 *                         that holds that supplier's stock count manually.
 *                         For merchants who track supplier stock outside MSI.
 *
 *   - disabled            Auto-toggle is off. The repricing engine still
 *                         runs when merchants manually flip slot active flags.
 *
 * MSI modes use soft-detect (interface_exists) — module installs and works
 * on stripped builds without MSI; those installs just can't pick MSI modes
 * in admin (admin source-model filters them out).
 */
class StockTrigger
{
    public function __construct(
        private readonly Config $config,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Returns true when this slot's configured stock source is at-or-below 0
     * (i.e. "the auto-toggle should flip the slot's active flag to no").
     *
     * Returns false on disabled mode OR when the slot's stock source has stock.
     */
    public function isStockExhausted(ProductInterface $product, Slot $slot, ?int $storeId = null): bool
    {
        $mode = $this->config->getStockTriggerSource($storeId);
        if ($mode === Config::STOCK_TRIGGER_DISABLED) {
            return false;
        }

        $qty = $this->resolveQty($product, $slot, $mode);
        return $qty !== null && $qty <= 0;
    }

    /**
     * Returns true when stock has come back — used by the reverse-toggle
     * mechanism to flip an inactive slot back on.
     */
    public function isStockRestored(ProductInterface $product, Slot $slot, ?int $storeId = null): bool
    {
        $mode = $this->config->getStockTriggerSource($storeId);
        if ($mode === Config::STOCK_TRIGGER_DISABLED) {
            return false;
        }

        $qty = $this->resolveQty($product, $slot, $mode);
        return $qty !== null && $qty > 0;
    }

    /**
     * Returns the qty value (float) for the given product+slot under the
     * configured trigger mode. Returns null when the mode can't resolve a
     * qty (missing attribute, MSI not installed, etc.) — caller treats
     * null as "no opinion, don't auto-toggle".
     */
    private function resolveQty(ProductInterface $product, Slot $slot, string $mode): ?float
    {
        $productId = (int) $product->getId();

        switch ($mode) {
            case Config::STOCK_TRIGGER_MAGENTO_QTY:
                try {
                    $stockItem = $this->stockRegistry->getStockItem($productId);
                    return $stockItem ? (float) $stockItem->getQty() : null;
                } catch (\Throwable $e) {
                    $this->logger->debug(
                        'ETechFlow_SupplierAutoflow: magento_qty trigger read failed.',
                        ['product_id' => $productId, 'exception' => $e->getMessage()]
                    );
                    return null;
                }

            case Config::STOCK_TRIGGER_MSI_DEFAULT:
                return $this->readMsiQty((string) $product->getSku(), 'default');

            case Config::STOCK_TRIGGER_MSI_PER_SLOT:
                if ($slot->stockSourceCode === null || $slot->stockSourceCode === '') {
                    return null;
                }
                return $this->readMsiQty((string) $product->getSku(), $slot->stockSourceCode);

            case Config::STOCK_TRIGGER_PER_SLOT_QTY:
                if ($slot->qtyAttr === null || $slot->qtyAttr === '') {
                    return null;
                }
                $raw = $product->getData($slot->qtyAttr);
                if ($raw === null || $raw === '' || !is_numeric($raw)) {
                    return null;
                }
                return (float) $raw;

            default:
                return null;
        }
    }

    /**
     * Soft-detect MSI's GetSourceItemsBySkuInterface, look up qty for the
     * given source code. Returns null when MSI isn't installed or no row
     * exists for that sku+source.
     */
    private function readMsiQty(string $sku, string $sourceCode): ?float
    {
        if (!interface_exists('\Magento\InventoryApi\Api\GetSourceItemsBySkuInterface')) {
            return null;
        }
        try {
            $svc = ObjectManager::getInstance()->get(
                \Magento\InventoryApi\Api\GetSourceItemsBySkuInterface::class
            );
            $items = $svc->execute($sku);
            foreach ($items as $item) {
                if ((string) $item->getSourceCode() === $sourceCode) {
                    return (float) $item->getQuantity();
                }
            }
            return null;
        } catch (\Throwable $e) {
            $this->logger->debug(
                'ETechFlow_SupplierAutoflow: MSI qty lookup failed.',
                ['sku' => $sku, 'source' => $sourceCode, 'exception' => $e->getMessage()]
            );
            return null;
        }
    }
}
