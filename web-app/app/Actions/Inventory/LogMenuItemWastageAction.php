<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Branch;
use App\Models\IngredientStock;
use App\Models\MenuItem;
use App\Models\User;
use App\Models\Wastage;
use App\Services\Accounting\AccountingService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Log wastage of finished menu items: every recipe line is taken out of
 * stock in base units multiplied by the wasted portions, exactly the way
 * a sale deducts them. The explosion is frozen on the wastage's line rows
 * so deleting the entry later puts back exactly what was taken, even if
 * the recipe has changed by then. Cost is always calculated server-side
 * from the branch's weighted-average costs.
 */
class LogMenuItemWastageAction
{
    public function __construct(private readonly StockService $stock) {}

    public function execute(
        Branch $branch,
        MenuItem $item,
        float $portions,
        string $reasonCode,
        ?string $note,
        ?User $user = null,
    ): Wastage {
        if (! in_array($reasonCode, Wastage::REASONS, true)) {
            throw new InvalidArgumentException('Unknown wastage reason code.');
        }

        if ($portions <= 0) {
            throw new InvalidArgumentException('Wastage quantity must be positive.');
        }

        $item->loadMissing('recipe.items.ingredient.unit');

        if ($item->recipe === null || $item->recipe->items->isEmpty()) {
            throw ValidationException::withMessages([
                'menu_item_id' => __('inventory.wastage_no_recipe', ['item' => $item->name]),
            ]);
        }

        return DB::transaction(function () use ($branch, $item, $portions, $reasonCode, $note, $user): Wastage {
            $stocks = IngredientStock::query()
                ->where('branch_id', $branch->id)
                ->whereIn('ingredient_id', $item->recipe->items->pluck('ingredient_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('ingredient_id');

            $lines = $item->recipe->items->map(function ($recipeLine) use ($stocks, $portions): ?array {
                $ingredient = $recipeLine->ingredient;

                if ($ingredient === null || $ingredient->unit === null) {
                    return null;
                }

                $baseQty = round((float) $recipeLine->qty * $portions, 6);
                $stock = $stocks->get($ingredient->id);
                $avgCost = (float) ($stock === null ? 0 : $stock->avg_cost);

                return [
                    'ingredient' => $ingredient,
                    // Kept in the ingredient's own unit for the history.
                    'qty' => $ingredient->unit->fromBase($baseQty),
                    'base_qty' => $baseQty,
                    'cost' => round($baseQty * $avgCost, 2),
                    'avg_cost' => $avgCost,
                ];
            })->filter()->values();

            $wastage = Wastage::query()->create([
                'branch_id' => $branch->id,
                'menu_item_id' => $item->id,
                'qty' => $portions,
                'reason_code' => $reasonCode,
                'note' => $note,
                'cost' => round((float) $lines->sum('cost'), 2),
                'user_id' => $user?->id,
            ]);

            foreach ($lines as $line) {
                $wastage->lines()->create([
                    'ingredient_id' => $line['ingredient']->id,
                    'unit_id' => $line['ingredient']->unit_id,
                    'qty' => $line['qty'],
                    'base_qty' => $line['base_qty'],
                    'cost' => $line['cost'],
                ]);

                $this->stock->adjust(
                    branchId: $branch->id,
                    ingredientId: $line['ingredient']->id,
                    baseQtyDelta: -$line['base_qty'],
                    movementType: 'wastage',
                    reference: $wastage,
                    unitCost: $line['avg_cost'],
                    userId: $user?->id,
                );
            }

            app(AccountingService::class)->postWastage($wastage->fresh(), $user);

            activity()
                ->performedOn($wastage)
                ->causedBy($user)
                ->withProperties(['menu_item' => $item->name, 'portions' => $portions])
                ->log('wastage.logged');

            return $wastage;
        });
    }
}
