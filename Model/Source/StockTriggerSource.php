<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model\Source;

use ETechFlow\SupplierAutoflow\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

class StockTriggerSource implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::STOCK_TRIGGER_MAGENTO_QTY,
             'label' => __('Magento product quantity (legacy / non-MSI)')],
            ['value' => Config::STOCK_TRIGGER_MSI_DEFAULT,
             'label' => __('MSI default source')],
            ['value' => Config::STOCK_TRIGGER_MSI_PER_SLOT,
             'label' => __('MSI per-slot source (slot config carries source code)')],
            ['value' => Config::STOCK_TRIGGER_PER_SLOT_QTY,
             'label' => __('Per-slot quantity attribute (slot config carries qty attr code)')],
            ['value' => Config::STOCK_TRIGGER_DISABLED,
             'label' => __('Disabled (auto-toggle off)')],
        ];
    }
}
