<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Inventory\DeleteWastageAction;
use App\Actions\Inventory\LogMenuItemWastageAction;
use App\Actions\Inventory\LogWastageAction;
use App\Actions\Inventory\ReviseWastageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviseWastageRequest;
use App\Http\Requests\Admin\WastageRequest;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Unit;
use App\Models\Wastage;
use App\Services\CostingService;
use App\Support\DeleteReasons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WastageController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('inventory.view');

        $branch = $this->currentBranch($request);

        $wastages = Wastage::query()
            ->visible()
            ->where('branch_id', $branch->id)
            ->with([
                'ingredient' => fn ($query) => $query->select('id', 'name')->withTrashed(),
                'unit:id,symbol',
                'user:id,name',
                'menuItem:id,name',
                'lines.ingredient' => fn ($query) => $query->select('id', 'name')->withTrashed(),
                'lines.unit:id,symbol',
            ])
            ->latest()
            ->limit(200)
            ->get();

        return Inertia::render('admin/wastage/Index', [
            'wastages' => $wastages,
            'ingredients' => Ingredient::query()->active()->with('unit:id,symbol')->orderBy('name')->get(['id', 'name', 'unit_id']),
            'units' => Unit::query()->orderBy('type')->orderBy('factor')->get(['id', 'name', 'symbol', 'type', 'factor']),
            'reasons' => Wastage::REASONS,
            // Only dishes with a recipe can be wasted, since that is what deducts stock.
            'menuItems' => MenuItem::query()->where('branch_id', $branch->id)->has('recipe')->orderBy('name')->get(['id', 'name', 'name_bn']),
            'canLog' => $request->user('staff')?->can('wastage.log'),
            'canDelete' => (bool) $request->user('staff')?->can('wastage.log'),
        ]);
    }

    public function store(WastageRequest $request, LogWastageAction $action, LogMenuItemWastageAction $menuItemAction): RedirectResponse
    {
        $branch = $this->currentBranch($request);

        if ($request->filled('menu_item_id')) {
            $item = MenuItem::query()->findOrFail((int) $request->input('menu_item_id'));

            abort_unless($item->branch_id === $branch->id, 404);

            $menuItemAction->execute(
                branch: $branch,
                item: $item,
                portions: (float) $request->validated('qty'),
                reasonCode: (string) $request->validated('reason_code'),
                note: $request->validated('note'),
                user: $request->user('staff'),
            );

            return back()->with('success', __('inventory.wastage_menu_logged', ['item' => $item->name, 'qty' => (string) $request->validated('qty')]));
        }

        $action->execute(
            branch: $branch,
            ingredient: Ingredient::query()->findOrFail((int) $request->validated('ingredient_id')),
            unit: Unit::query()->findOrFail((int) $request->validated('unit_id')),
            qty: (float) $request->validated('qty'),
            reasonCode: (string) $request->validated('reason_code'),
            note: $request->validated('note'),
            user: $request->user('staff'),
        );

        return back()->with('success', __('inventory.wastage_logged'));
    }

    /**
     * What wasting one portion of the item takes out of stock: the recipe
     * lines and their cost, so the form can preview it before saving.
     */
    public function menuItem(Request $request, MenuItem $item, CostingService $costing): JsonResponse
    {
        $this->authorize('wastage.log');

        $branch = $this->currentBranch($request);

        abort_unless($item->branch_id === $branch->id, 404);

        $data = $costing->recipeCost($item, $branch->id);

        // Recipe quantities live in base units; show them in the unit the
        // kitchen knows the ingredient by.
        $ingredients = Ingredient::query()
            ->with('unit:id,symbol,factor')
            ->whereKey(array_column($data['lines'], 'ingredient_id'))
            ->get()
            ->keyBy('id');

        $data['lines'] = array_map(function (array $line) use ($ingredients): array {
            $ingredient = $ingredients->get($line['ingredient_id']);
            $factor = $ingredient === null || $ingredient->unit === null ? 1 : (float) $ingredient->unit->factor;

            $line['qty'] = number_format($factor > 0 ? ((float) $line['qty'] / $factor) : (float) $line['qty'], 3, '.', '');

            return $line;
        }, $data['lines']);

        return response()->json($data);
    }

    /**
     * Fix the quantity, reason or note of a wastage entry. Stock and the
     * accounting entry follow the change; the dish or ingredient itself
     * stays, because that is what the stock movement belongs to.
     */
    public function update(ReviseWastageRequest $request, Wastage $wastage, ReviseWastageAction $action): RedirectResponse
    {
        $this->authorize('wastage.log');

        $user = $request->user('staff');
        abort_unless($user->canAccessBranch($wastage->branch_id), 404);

        $action->execute(
            wastage: $wastage,
            qty: (float) $request->validated('qty'),
            reasonCode: (string) $request->validated('reason_code'),
            note: $request->validated('note'),
            user: $user,
        );

        return back()->with('success', __('inventory.wastage_updated'));
    }

    public function destroy(Request $request, Wastage $wastage, DeleteWastageAction $action): RedirectResponse
    {
        $this->authorize('wastage.log');

        $user = $request->user('staff');
        abort_unless($user->canAccessBranch($wastage->branch_id), 404);

        $reason = DeleteReasons::validate($request);

        $action->execute($wastage, $user, $reason['reason'], $reason['note']);

        return back()->with('success', __('inventory.wastage_deleted'));
    }

    private function currentBranch(Request $request): Branch
    {
        $user = $request->user('staff');

        $branch = Branch::query()->find($user->branch_id ?? (int) session('branch_id', 0));

        return $branch ?? Branch::query()->orderBy('id')->firstOrFail();
    }
}
