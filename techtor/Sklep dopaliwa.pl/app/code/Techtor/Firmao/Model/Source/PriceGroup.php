<?php

declare(strict_types=1);

namespace Techtor\Firmao\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Grupy cenowe Firmao — do selecta w konfiguracji admin.
 *
 * Firmao: A = bazowa, B = hurt 1, C = hurt 2, itd.
 */
class PriceGroup implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'A', 'label' => __('A — Bazowa (detaliczna)')],
            ['value' => 'B', 'label' => __('B — Hurt 1')],
            ['value' => 'C', 'label' => __('C — Hurt 2')],
            ['value' => 'D', 'label' => __('D — Hurt 3')],
            ['value' => 'E', 'label' => __('E — Specjalna')],
        ];
    }
}
