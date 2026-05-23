<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model\Source;

use ETechFlow\SupplierAutoflow\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

class OutputTarget implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::OUTPUT_PRICE,
             'label' => __('Regular price (straight markup, no strikethrough)')],
            ['value' => Config::OUTPUT_SPECIAL_PRICE,
             'label' => __('Special price only (merchant manages regular price manually)')],
            ['value' => Config::OUTPUT_SPECIAL_PRICE_WITH_ANCHOR,
             'label' => __('Special price + auto-anchored regular price (recommended)')],
        ];
    }
}
