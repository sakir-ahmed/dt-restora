<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\TableBoard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Table view: the floor as a board of tables and chairs. An order starts from here by picking tables and seats
 * (or no table at all) and then opens in the POS, which does the ordering as always.
 */
class FloorController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('pos.access');

        $branch = $this->currentBranch($request);

        $tables = TableBoard::forBranch($branch)->map(function (array $table): array {
            $booking = $table['booking'];
            $startsAt = $booking !== null ? now()->parse($booking['starts_at']) : null;

            $state = match (true) {
                $table['status'] === 'occupied' && ($table['last_order']['status'] ?? null) === 'paid' => 'billed',
                $table['status'] === 'occupied' => 'running',
                $startsAt !== null && $startsAt->lte(now()) => 'reserved',
                $startsAt !== null && $startsAt->lte(now()->addMinutes(60)) => 'soon',
                default => 'available',
            };

            return $table + ['state' => $state];
        })->values();

        return Inertia::render('admin/pos/Floor', [
            'tables' => $tables,
            'branchName' => $branch->name,
        ]);
    }

    private function currentBranch(Request $request): Branch
    {
        $user = $request->user('staff');

        $branch = Branch::query()->find($user->branch_id ?? (int) session('branch_id', 0));

        return $branch ?? Branch::query()->orderBy('id')->firstOrFail();
    }
}
