<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Sales\CloseShiftAction;
use App\Actions\Sales\OpenShiftAction;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function open(Request $request, OpenShiftAction $action): RedirectResponse
    {
        $this->authorize('shifts.manage');

        $branch = $this->currentBranch($request);

        $validated = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'terminal' => ['nullable', 'string', 'max:10'],
        ]);

        $action->execute(
            branch: $branch,
            cashier: $request->user('staff'),
            openingCash: (float) $validated['opening_cash'],
            terminalCode: (string) ($validated['terminal'] ?? 'T01'),
        );

        return back()->with('success', __('orders.shift_opened'));
    }

    public function close(Request $request, Shift $shift, CloseShiftAction $action): RedirectResponse
    {
        $this->authorize('shifts.manage');

        $user = $request->user('staff');

        abort_unless($user->canAccessBranch($shift->branch_id), 404);
        abort_unless($user->id === $shift->user_id || $user->can('orders.void'), 403);

        $validated = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
        ]);

        $closed = $action->execute($shift, (float) $validated['counted_cash']);
        $summary = $action->summary($closed);

        return back()->with('success', __('orders.shift_closed', [
            'expected' => $closed->expected_cash,
            'difference' => $closed->difference,
        ]))->with('shift_closed', [
            'id' => $closed->id,
            'opening_cash' => $closed->opening_cash,
            'counted_cash' => $closed->counted_cash,
            'expected_cash' => $closed->expected_cash,
            'difference' => $closed->difference,
            'orders' => $summary['orders'],
            'open_orders' => $summary['open_orders'],
            'sales_total' => $summary['sales_total'],
            'cash_sales' => $summary['cash_sales'],
            'methods' => $summary['methods'],
        ]);
    }

    /**
     * What the cashier should know before counting the drawer: bills of this shift still unpaid. The expected cash
     * is not shown here, so the count is made without looking at it.
     */
    public function summary(Request $request, Shift $shift, CloseShiftAction $action): JsonResponse
    {
        $this->authorize('shifts.manage');

        $user = $request->user('staff');

        abort_unless($user->canAccessBranch($shift->branch_id), 404);
        abort_unless($user->id === $shift->user_id || $user->can('orders.void'), 403);

        $summary = $action->summary($shift);

        return response()->json([
            'orders' => $summary['orders'],
            'open_orders' => $summary['open_orders'],
            'opened_at' => $shift->opened_at?->toIso8601String(),
        ]);
    }

    private function currentBranch(Request $request): Branch
    {
        $user = $request->user('staff');

        $branch = Branch::query()->find($user->branch_id ?? (int) session('branch_id', 0));

        return $branch ?? Branch::query()->orderBy('id')->firstOrFail();
    }
}
