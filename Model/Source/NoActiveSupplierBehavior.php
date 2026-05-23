<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model\Source;

use ETechFlow\SupplierAutoflow\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

class NoActiveSupplierBehavior implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::NO_ACTIVE_OUT_OF_STOCK,
             'label' => __('Set product to out of stock (recommended)')],
            ['value' => Config::NO_ACTIVE_DISABLE_PRODUCT,
             'label' => __('Disable product entirely')],
            ['value' => Config::NO_ACTIVE_LEAVE_WITH_WARN,
             'label' => __('Leave unchanged, log warning')],
        ];
    }
}
