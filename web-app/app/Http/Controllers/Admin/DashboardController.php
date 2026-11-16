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
    /**
     * What a role without dashboard.view sees instead of the sales figures: the same prop shapes,
     * all zero, so the page contract stays stable and nothing leaks.
     */
    private const EMPTY_SUMMARY = [
        'gross_sales' => '0.00',
        'net_sales' => '0.00',
        'discount' => '0.00',
        'vat' => '0.00',
        'service_charge' => '0.00',
        'food_cost' => '0.00',
        'wastage_cost' => '0.00',
        'salary_cost' => '0.00',
        'expenses' => '0.00',
        'commission' => '0.00',
        'net_profit' => '0.00',
        'orders' => 0,
        'order_items' => 0,
    ];

    private const EMPTY_DUES = [
        'customers' => 0,
        'oldest_days' => 0,
        'top' => [],
        'outstanding' => '0.00',
        'open_count' => 0,
        'new_due' => '0.00',
        'collected' => '0.00',
    ];

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
            'summary' => $seesMetrics ? $metrics->summary($branchId, $from, $to) : self::EMPTY_SUMMARY,
            'previous' => $seesMetrics ? $metrics->summary($branchId, $prevFrom, $prevTo) : self::EMPTY_SUMMARY,
            'salesByDay' => $seesMetrics ? $metrics->salesByDay($branchId, $from, $to) : [],
            'salesByChannel' => $seesMetrics ? $metrics->salesByChannel($branchId, $from, $to) : [],
            'topItems' => $seesMetrics ? $metrics->topItems($branchId, $from, $to) : [],
            'expensesByCategory' => $seesMetrics ? $metrics->expensesByCategory($branchId, $from, $to) : [],
            'dues' => $seesMetrics ? $metrics->dues($branchId, $from, $to) : self::EMPTY_DUES,
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
            'waitingPreorders' => $this->waitingPreorders($branchId, $seesPreorders, $seesReservations),
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
     * The pre-orders still waiting, soonest first: menu-book pre-orders and bookings that carry a
     * pre-ordered menu. A short list for the dashboard card.
     *
     * @return array<int, array{kind: string, no: string, name: ?string, at: ?string, total: string, items: int}>
     */
    private function waitingPreorders(?int $branchId, bool $seesPreorders, bool $seesReservations): array
    {
        $menuBook = $seesPreorders ? Preorder::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', Preorder::PENDING)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Preorder $preorder): array => [
                'kind' => 'menu_book',
                'no' => $preorder->order_no,
                'name' => $preorder->customer_name,
                'at' => ($preorder->expected_at ?? $preorder->created_at)->toIso8601String(),
                'planned' => $preorder->expected_at !== null,
                'total' => (string) $preorder->total,
                'items' => $preorder->itemCount(),
            ]) : collect();

        $bookings = $seesReservations ? Reservation::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', Reservation::BOOKED)
            ->whereNotNull('preorder')
            ->orderBy('starts_at')
            ->limit(50)
            ->get()
            ->map(fn (Reservation $reservation): array => [
                'kind' => 'booking',
                'no' => $reservation->booking_no,
                'name' => $reservation->guest_name,
                'at' => $reservation->starts_at->toIso8601String(),
                'planned' => true,
                'total' => (string) $reservation->preorder_total,
                'items' => (int) collect($reservation->preorder)->sum('quantity'),
            ]) : collect();

        return $menuBook->concat($bookings)->sortBy('at')->take(30)->values()->all();
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
