<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Psr\Log\LoggerInterface;

/**
 * First-active-wins iteration over the configured supplier slots (v0.1.0).
 *
 * Given a product, walks slots in admin-priority order and returns the
 * first one whose active-attribute is truthy. That slot represents the
 * supplier we'd actually ship from — its cost + markup drive the price,
 * its name drives downstream eligibility decisions (e.g. NDE).
 *
 * Per-request memoization keyed by productId so repeated calls in one
 * checkout / save chain are cheap.
 */
class SlotResolver
{
    /** @var array<int, array{slot: Slot, product: ProductInterface}|null> */
    private array $cache = [];

    public function __construct(
        private readonly Config $config,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Returns the first-active slot for the given product, plus the loaded
     * product object (so callers can read cost/markup/anchor attrs without
     * a second DB round-trip).
     *
     * @return array{slot: Slot, product: ProductInterface}|null
     *         null when no slot is active OR when the module/config is empty
     */
    public function resolve(int $productId, ?int $storeId = null): ?array
    {
        if (isset($this->cache[$productId])) {
            return $this->cache[$productId];
        }

        $slots = $this->config->getSlots($storeId);
        if (empty($slots)) {
            return $this->cache[$productId] = null;
        }

        // Build a single collection-load with every attribute any slot needs.
        $attrCodes = [];
        foreach ($slots as $slot) {
            foreach ($slot->attributeCodes() as $code) {
                $attrCodes[$code] = true;
            }
        }
        $anchorAttr = $this->config->getAnchorAttribute($storeId);
        if ($anchorAttr !== '') {
            $attrCodes[$anchorAttr] = true;
        }

        $product = $this->loadProduct($productId, array_keys($attrCodes));
        if ($product === null) {
            return $this->cache[$productId] = null;
        }

        foreach ($slots as $slot) {
            $active = $product->getData($slot->activeAttr);
            if ((int) $active === 1 || $active === '1' || $active === true) {
                return $this->cache[$productId] = ['slot' => $slot, 'product' => $product];
            }
        }

        return $this->cache[$productId] = null;
    }

    /**
     * Resolve a slot's supplier-name attribute to a string (handles text +
     * dropdown + multiselect). Mirrors NDE v1.6.3's resolution logic so the
     * same product reads the same supplier name on both modules.
     */
    public function resolveSupplierName(ProductInterface $product, Slot $slot): ?string
    {
        $raw = $product->getData($slot->nameAttr);
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }

        // Text attribute → use raw value
        if (is_string($raw) && !is_numeric($raw) && !str_contains($raw, ',')) {
            return $raw;
        }

        // Dropdown / multiselect → look up the source label
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $slot->nameAttr);
            if ($attribute && $attribute->getId() && $attribute->usesSource()) {
                $label = $attribute->getSource()->getOptionText($raw);
                if (is_string($label) && $label !== '') {
                    return $label;
                }
                if (is_array($label) && !empty($label)) {
                    return implode(', ', array_filter(array_map(
                        static fn($v) => is_scalar($v) ? (string) $v : '',
                        $label
                    )));
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                'ETechFlow_SupplierAutoflow: failed to resolve supplier name from EAV source.',
                ['product_id' => (int) $product->getId(), 'attr' => $slot->nameAttr, 'exception' => $e->getMessage()]
            );
        }

        return is_scalar($raw) ? (string) $raw : null;
    }

    public function resetCache(): void
    {
        $this->cache = [];
    }

    /**
     * @param string[] $attrCodes
     */
    private function loadProduct(int $productId, array $attrCodes): ?ProductInterface
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter([$productId]);
        foreach ($attrCodes as $code) {
            $collection->addAttributeToSelect($code);
        }
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();
        return $item->getId() ? $item : null;
    }
}
