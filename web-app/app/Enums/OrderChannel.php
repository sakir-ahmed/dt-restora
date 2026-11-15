<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderChannel: string
{
    case Pos = 'pos';
    case Website = 'website';
    case Qr = 'qr';
    case Aggregator = 'aggregator';

    /**
     * Guest orders (website, online menu, table QR) are only placed. A manager sends them to the kitchen.
     */
    public function needsApproval(): bool
    {
        return in_array($this, [self::Website, self::Qr], true);
    }
}
