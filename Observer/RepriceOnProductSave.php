<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Observer;

use ETechFlow\SupplierAutoflow\Model\AuditLogger;
use ETechFlow\SupplierAutoflow\Model\RepriceService;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Listens for catalog_product_save_after and triggers reprice evaluation
 * for the saved product (v0.1.0).
 *
 * Fires on:
 *   - Merchant manually changes a slot's active flag in admin
 *   - Merchant manually changes a slot's cost or markup
 *   - AutoToggleService's writes (re-entry guarded in RepriceService)
 *   - ResyncCommand bulk re-evaluations (calls RepriceService directly,
 *     bypassing this observer)
 */
class RepriceOnProductSave implements ObserverInterface
{
    public function __construct(
        private readonly RepriceService $repriceService
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var ProductInterface|null $product */
        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) {
            return;
        }
        $this->repriceService->recompute(
            (int) $product->getId(),
            AuditLogger::TRIGGER_PRODUCT_SAVE
        );
    }
}
