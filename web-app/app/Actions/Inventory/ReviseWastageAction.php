<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Concerns\ReversesSourceJournal;
use App\Exceptions\InsufficientStockException;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\User;
use App\Models\Wastage;
use App\Services\Accounting\AccountingService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Fix a wastage entry that was logged with the wrong quantity, reason or
 * note. Stock moves by the difference between what is recorded and the new
 * amount, and the wastage journal is reversed and reposted when the cost
 * changed. The ingredient or dish itself cannot change here: a wrong pick
 * is deleted and logged again.
 */
class ReviseWastageAction
{
    use ReversesSourceJournal;

    public function __construct(private readonly StockService $stock) {}

    public function execute(
        Wastage $wastage,
        float $qty,
        string $reasonCode,
        ?string $note,
        ?User $user = null,
    ): Wastage {
        if (! in_array($reasonCode, Wastage::REASONS, true)) {
            throw new InvalidArgumentException('Unknown wastage reason code.');
        }

        if ($qty <= 0) {
            throw new InvalidArgumentException('Wastage quantity must be positive.');
        }

        return DB::transaction(function () use ($wastage, $qty, $reasonCode, $note, $user): Wastage {
            $wastage = Wastage::query()->whereKey($wastage->id)->lockForUpdate()->firstOrFail();

            $oldCost = (float) $wastage->cost;
            $oldQty = (float) $wastage->qty;

            $cost = $wastage->isMenuItemWastage()
                ? $this->reviseMenuLines($wastage, $qty)
                : $this->reviseIngredientQty($wastage, $qty);

            $wastage->update([
                'qty' => $qty,
                'reason_code' => $reasonCode,
                'note' => $note,
                'cost' => $cost,
            ]);

            if (abs($cost - $oldCost) > 0.001) {
                $this->reverseJournalsFor($wastage, $user, __('inventory.wastage_revise_journal_note', ['id' => $wastage->id]));

                app(AccountingService::class)->postWastage($wastage->fresh(), $user);
            }

            activity()
                ->performedOn($wastage)
                ->causedBy($user)
                ->withProperties(['from_qty' => $oldQty, 'to_qty' => $qty, 'from_cost' => $oldCost, 'to_cost' => $cost])
                ->log('wastage.revised');

            return $wastage;
        });
    }

    /**
     * Move stock for the changed amount of an ingredient wastage and
     * return its recomputed cost.
     */
    private function reviseIngredientQty(Wastage $wastage, float $qty): float
    {
        $wastage->loadMissing('unit', 'ingredient');

        $oldBase = $wastage->unit->toBase((string) $wastage->qty);
        $newBase = $wastage->unit->toBase($qty);

        $stock = IngredientStock::query()
            ->where('branch_id', $wastage->branch_id)
            ->where('ingredient_id', $wastage->ingredient_id)
            ->lockForUpdate()
            ->first();

        $avgCost = (float) ($stock->avg_cost ?? 0);

        try {
            // Stock already carries the old deduction: move by the difference.
            $this->adjustStock($wastage, (int) $wastage->ingredient_id, $oldBase - $newBase, $avgCost);
        } catch (InsufficientStockException) {
            throw ValidationException::withMessages([
                'qty' => __('inventory.insufficient_stock', ['ingredient' => (string) $wastage->ingredient?->name]),
            ]);
        }

        return round($newBase * $avgCost, 2);
    }

    /**
     * Rebuild the recipe explosion for the new portions from the item's
     * current recipe, move stock by the per-ingredient difference and
     * return the recomputed cost.
     */
    private function reviseMenuLines(Wastage $wastage, float $portions): float
    {
        $item = $wastage->menuItem()->with('recipe.items.ingredient.unit')->firstOrFail();

        if ($item->recipe === null || $item->recipe->items->isEmpty()) {
            throw ValidationException::withMessages([
                'qty' => __('inventory.wastage_no_recipe', ['item' => $item->name]),
            ]);
        }

        $wastage->loadMissing('lines');

        $oldLines = $wastage->lines->mapWithKeys(fn ($line) => [$line->ingredient_id => (float) $line->base_qty]);

        $stocks = IngredientStock::query()
            ->where('branch_id', $wastage->branch_id)
            ->whereIn('ingredient_id', $item->recipe->items->pluck('ingredient_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('ingredient_id');

        $newLines = [];
        $total = 0.0;

        try {
            foreach ($item->recipe->items as $recipeLine) {
                $ingredient = $recipeLine->ingredient;

                if ($ingredient === null || $ingredient->unit === null) {
                    continue;
                }

                $baseQty = round((float) $recipeLine->qty * $portions, 6);
                $stock = $stocks->get($ingredient->id);
                $avgCost = (float) ($stock === null ? 0 : $stock->avg_cost);
                $lineCost = round($baseQty * $avgCost, 2);
                $total += $lineCost;

                $this->adjustStock($wastage, $ingredient->id, ($oldLines->get($ingredient->id) ?? 0) - $baseQty, $avgCost);

                $newLines[] = [
                    'ingredient_id' => $ingredient->id,
                    'unit_id' => $ingredient->unit_id,
                    'qty' => $ingredient->unit->fromBase($baseQty),
                    'base_qty' => $baseQty,
                    'cost' => $lineCost,
                ];
            }

            // Recipe lines that have since disappeared give their stock back.
            foreach ($oldLines->keys()->diff(collect($newLines)->pluck('ingredient_id')) as $ingredientId) {
                $this->adjustStock($wastage, (int) $ingredientId, (float) $oldLines->get($ingredientId), null);
            }
        } catch (InsufficientStockException $exception) {
            throw ValidationException::withMessages([
                'qty' => __('inventory.insufficient_stock', ['ingredient' => Ingredient::query()->whereKey($exception->ingredientId)->value('name') ?? '']),
            ]);
        }

        $wastage->lines()->delete();

        $wastage->lines()->createMany($newLines);

        return round($total, 2);
    }

    private function adjustStock(Wastage $wastage, int $ingredientId, float $baseQtyDelta, ?float $unitCost): void
    {
        if (abs($baseQtyDelta) < 0.000001) {
            return;
        }

        $this->stock->adjust(
            branchId: $wastage->branch_id,
            ingredientId: $ingredientId,
            baseQtyDelta: $baseQtyDelta,
            movementType: 'wastage',
            reference: $wastage,
            unitCost: $unitCost,
        );
    }
}
