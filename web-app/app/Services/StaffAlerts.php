<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Services\Metrics\MetricsService;
use Illuminate\Support\Facades\Cache;

/**
 * The bell's alerts: low stock and wastage spikes, shared by the web panel's Inertia prop and
 * the mobile app's notifications endpoint. Read state is kept per person as fingerprints, so an
 * alert reappears when its text changes.
 */
class StaffAlerts
{
    /**
     * Every alert for the person's branch, read or not (cached for a minute, shared by the
     * people on that branch).
     *
     * @return array<int, array{id: string, icon: string, title: string, body: string, href: string}>
     */
    public function all(User $user, ?int $branchId): array
    {
        return Cache::remember('admin-alerts:'.($branchId ?? 'all').':'.app()->getLocale(), 60, fn (): array => $this->notifications($branchId));
    }

    /**
     * The alerts the person has not marked as read.
     *
     * @return array<int, array{id: string, icon: string, title: string, body: string, href: string}>
     */
    public function unread(User $user, ?int $branchId): array
    {
        // Alerts the person marked as read stay hidden until their text changes.
        $read = (array) $user->notifications_read;

        return array_values(array_filter(
            $this->all($user, $branchId),
            fn (array $item): bool => ($read[$item['id']] ?? null) !== self::fingerprint($item),
        ));
    }

    /**
     * Mark every alert in the bell as read for the person. An alert shows again when its text
     * changes, for example when more ingredients run low.
     */
    public function markAllRead(User $user, ?int $branchId): void
    {
        $user->forceFill([
            'notifications_read' => collect($this->all($user, $branchId))
                ->mapWithKeys(fn (array $item): array => [$item['id'] => self::fingerprint($item)])
                ->all(),
        ])->save();
    }

    /**
     * @param  array{id: string, title: string, body: string}  $item
     */
    public static function fingerprint(array $item): string
    {
        return md5($item['id'].'|'.$item['title'].'|'.$item['body']);
    }

    /**
     * @return array<int, array{id: string, icon: string, title: string, body: string, href: string}>
     */
    private function notifications(?int $branchId): array
    {
        $metrics = app(MetricsService::class);
        $items = [];

        $lowStock = $metrics->lowStock($branchId);

        if ($lowStock !== []) {
            $items[] = [
                'id' => 'low-stock',
                'icon' => 'boxes-stacked',
                'title' => __('dashboard.alerts.low_stock_count', ['count' => count($lowStock)]),
                'body' => $lowStock[0]['name'],
                'href' => '/admin/ingredients',
            ];
        }

        $spike = $metrics->wastageSpike($branchId);

        if ($spike !== null) {
            $items[] = [
                'id' => 'wastage-spike',
                'icon' => 'trash',
                'title' => __('dashboard.alerts.wastage_spike'),
                'body' => $spike['today'],
                'href' => '/admin/wastage',
            ];
        }

        return $items;
    }
}
