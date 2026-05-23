<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Observer;

use ETechFlow\SupplierAutoflow\Model\AuditLogger;
use ETechFlow\SupplierAutoflow\Model\AutoToggleService;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Listens for legacy cataloginventory_stock_item_save_after and triggers
 * auto-toggle evaluation for the affected product (v0.1.0).
 *
 * MSI flows are covered separately by Plugin\AutoToggleOnMsiSourceItemsSave
 * (mirroring NDE's v1.6.0 pattern).
 */
class AutoToggleOnStockChange implements ObserverInterface
{
    public function __construct(
        private readonly AutoToggleService $autoToggleService
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var StockItemInterface|null $stockItem */
        $stockItem = $observer->getEvent()->getItem();
        if (!$stockItem || !$stockItem->getProductId()) {
            return;
        }
        $this->autoToggleService->evaluate(
            (int) $stockItem->getProductId(),
            AuditLogger::TRIGGER_STOCK_SAVE
        );
    }
}
