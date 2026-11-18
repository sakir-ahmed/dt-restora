<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WastageItem extends Model
{
    protected $fillable = [
        'wastage_id', 'ingredient_id', 'unit_id', 'qty', 'base_qty', 'cost',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'base_qty' => 'decimal:6',
        'cost' => 'decimal:2',
    ];

    /** @return BelongsTo<Wastage, $this> */
    public function wastage(): BelongsTo
    {
        return $this->belongsTo(Wastage::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
