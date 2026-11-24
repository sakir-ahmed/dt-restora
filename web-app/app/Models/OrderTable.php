<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One table of an order, with the seat numbers its guests took there (empty when seats were not picked).
 */
class OrderTable extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'restaurant_table_id', 'seats'];

    protected $casts = ['seats' => 'array'];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<RestaurantTable, $this> */
    public function restaurantTable(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id')->withoutGlobalScopes();
    }
}
