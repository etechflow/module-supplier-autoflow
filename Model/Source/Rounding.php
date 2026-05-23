<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Model\Source;

use ETechFlow\SupplierAutoflow\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

class Rounding implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::ROUND_2DP,     'label' => __('2 decimal places (£4.35)')],
            ['value' => Config::ROUND_5P,      'label' => __('Nearest 5p (£4.35)')],
            ['value' => Config::ROUND_10P,     'label' => __('Nearest 10p (£4.40)')],
            ['value' => Config::ROUND_99P_END, 'label' => __('99p ending (£4.99)')],
            ['value' => Config::ROUND_NONE,    'label' => __('No rounding (raw)')],
        ];
    }
}
