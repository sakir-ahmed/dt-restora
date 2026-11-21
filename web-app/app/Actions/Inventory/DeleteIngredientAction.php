<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Ingredient;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteIngredientAction
{
    /**
     * Delete an ingredient entered by mistake (a double import, a typo): the row is
     * kept marked as deleted and every history that names it still resolves. Its
     * recipe lines are another matter: a deleted ingredient must not sit inside a
     * recipe, so those lines are removed and each recipe's cost is worked out
     * again from what is left. The menu items touched are named in the log.
     */
    public function execute(Ingredient $ingredient, ?User $user = null, ?string $reason = null, ?string $note = null): Ingredient
    {
        return DB::transaction(function () use ($ingredient, $user, $reason, $note): Ingredient {
            $ingredient = Ingredient::query()->whereKey($ingredient->id)->lockForUpdate()->firstOrFail();

            $menuItems = $ingredient->recipeItems()
                ->with('recipe.menuItem:id,name')
                ->get()
                ->map(fn (RecipeItem $item): ?string => $item->recipe?->menuItem?->name)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $ingredient->recipeItems()->delete();

            $ingredient->forceFill([
                'deleted_by' => $user?->id,
                'delete_reason' => $reason,
                'delete_note' => $note,
            ])->save();

            $ingredient->delete();

            activity()
                ->performedOn($ingredient)
                ->causedBy($user)
                ->withProperties(['reason' => $reason, 'note' => $note, 'recipes_modified' => $menuItems])
                ->log('ingredient.deleted');

            return $ingredient;
        });
    }
}
