<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Preorder;
use App\Models\Recipe;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Metrics\MetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MetricsService $metrics): Response
    {
        [$from, $to, $period] = $this->period($request);

        [$prevFrom, $prevTo] = $this->previousPeriod($from, $to);

        $user = $request->user('staff');
        $branchId = $this->branchId($request, $user);
        $seesAll = $branchId === null;

        // Every role can open the dashboard. The sales figures behind it stay with roles
        // holding dashboard.view; the others get the cards their own permissions allow.
        $seesMetrics = $user->can('dashboard.view');
        $seesStock = $user->can('inventory.view');
        $seesPreorders = $user->can('preorders.view');
        $seesReservations = $user->can('reservations.view');

        $lowStock = $seesStock ? $metrics->lowStock($branchId ?? $this->firstBranchId()) : [];

        return Inertia::render('admin/Dashboard', [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'previousFrom' => $prevFrom->toDateString(),
            'previousTo' => $prevTo->toDateString(),
            'canViewMetrics' => $seesMetrics,
            'summary' => $seesMetrics ? $metrics->summary($branchId, $from, $to) : MetricsService::EMPTY_SUMMARY,
            'previous' => $seesMetrics ? $metrics->summary($branchId, $prevFrom, $prevTo) : MetricsService::EMPTY_SUMMARY,
            'salesByDay' => $seesMetrics ? $metrics->salesByDay($branchId, $from, $to) : [],
            'salesByChannel' => $seesMetrics ? $metrics->salesByChannel($branchId, $from, $to) : [],
            'salesByPaymentMethod' => $seesMetrics ? $metrics->salesByPaymentMethod($branchId, $from, $to) : [],
            'topItems' => $seesMetrics ? $metrics->topItems($branchId, $from, $to) : [],
            'expensesByCategory' => $seesMetrics ? $metrics->expensesByCategory($branchId, $from, $to) : [],
            'dues' => $seesMetrics ? $metrics->dues($branchId, $from, $to) : MetricsService::EMPTY_DUES,
            'branchComparison' => $seesMetrics && $seesAll ? $metrics->branchComparison($from, $to) : [],
            // Menu-book pre-orders still waiting, plus bookings that carry a pre-ordered menu.
            'preorders' => ($seesPreorders ? Preorder::query()
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->where('status', Preorder::PENDING)
                ->count() : 0)
                + ($seesReservations ? Reservation::query()
                    ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                    ->where('status', Reservation::BOOKED)
                    ->whereNotNull('preorder')
                    ->count() : 0),
            'lowStockItems' => array_slice($lowStock, 0, 50),
            'waitingPreorders' => $metrics->waitingPreorders($branchId, $seesPreorders, $seesReservations),
            'alerts' => [
                'lowStock' => count($lowStock),
                'wastageSpike' => $seesStock ? $metrics->wastageSpike($branchId ?? $this->firstBranchId()) : null,
            ],
            'setup' => [
                'branches' => Branch::count(),
                'menuItems' => MenuItem::count(),
                'ingredients' => Ingredient::count(),
                'recipes' => Recipe::count(),
                'users' => User::count(),
            ],
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function period(Request $request): array
    {
        $today = CarbonImmutable::today();

        return match ($request->string('period')->toString() ?: 'today') {
            'yesterday' => [$today->subDay()->startOfDay(), $today->subDay()->endOfDay(), 'yesterday'],
            '7days' => [$today->subDays(6)->startOfDay(), $today->endOfDay(), '7days'],
            'month' => [$today->startOfMonth(), $today->endOfDay(), 'month'],
            'last_month' => [$today->subMonth()->startOfMonth(), $today->subMonth()->endOfMonth(), 'last_month'],
            'custom' => [
                CarbonImmutable::parse((string) $request->input('from', $today->toDateString()))->startOfDay(),
                CarbonImmutable::parse((string) $request->input('to', $today->toDateString()))->endOfDay(),
                'custom',
            ],
            default => [$today->startOfDay(), $today->endOfDay(), 'today'],
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function previousPeriod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $length = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;

        return [$from->subDays($length)->startOfDay(), $from->subDay()->endOfDay()];
    }

    private function branchId(Request $request, $user): ?int
    {
        if ($user->branch_id !== null) {
            return (int) $user->branch_id;
        }

        $branchParam = (int) $request->input('branch', 0);

        if ($branchParam === -1) {
            return null;
        }

        if ($branchParam > 0) {
            return $branchParam;
        }

        $sessionBranch = (int) session('branch_id', 0);

        return $sessionBranch > 0 ? $sessionBranch : null;
    }

    private function firstBranchId(): ?int
    {
        $id = Branch::query()->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }
}
