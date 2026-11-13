<?php

namespace App\Reports;

/** One report column. type: text, date, datetime, int, qty, money, cost, percent. A cost column is hidden from people without products.view_cost. */
final class Col
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $cost = false,
        public readonly bool $sum = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type, 'sum' => $this->sum];
    }
}
