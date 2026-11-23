<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'kitchen_ticket_id', 'menu_item_id', 'variant_id', 'name', 'name_bn',
        'unit_price', 'quantity', 'add_ons', 'add_ons_total', 'line_total', 'line_cost',
        'line_discount_type', 'line_discount_value', 'line_vat_percent', 'line_extra_charge',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
        'add_ons' => 'array',
        'add_ons_total' => 'decimal:2',
        'line_total' => 'decimal:2',
        'line_cost' => 'decimal:4',
        'line_discount_value' => 'decimal:2',
        'line_vat_percent' => 'decimal:2',
        'line_extra_charge' => 'decimal:2',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<KitchenTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(KitchenTicket::class, 'kitchen_ticket_id');
    }

    /** @return BelongsTo<MenuItem, $this> */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /** @return BelongsTo<MenuItemVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(MenuItemVariant::class);
    }
}
