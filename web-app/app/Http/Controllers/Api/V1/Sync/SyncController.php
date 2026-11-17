<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Api\Concerns\InteractsWithApi;
use App\Http\Controllers\Api\Controller;
use App\Models\SyncItem;
use App\Services\Sync\SyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SyncController extends Controller
{
    use InteractsWithApi;

    public function __construct(private readonly SyncService $sync) {}

    /**
     * Full reference data for a fresh device cache.
     */
    public function bootstrap(Request $request): JsonResponse
    {
        return $this->success($this->sync->bootstrap($request->user()));
    }

    /**
     * Delta updates since a data version.
     */
    public function changes(Request $request): JsonResponse
    {
        $since = (int) $request->query('since', 0);

        return $this->success($this->sync->changes($request->user(), $since));
    }

    /**
     * Push queued offline writes. Each item gets its own result:
     * accepted, duplicate or conflict with a reason code.
     */
    public function push(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:200'],
            'items.*.client_id' => ['required', 'string', 'max:40'],
            'items.*.type' => ['required', 'in:'.implode(',', SyncItem::TYPES)],
            'items.*.payload' => ['required', 'array'],
            'items.*.created_at_device' => ['nullable', 'date'],
            'items.*.data_version' => ['nullable', 'integer'],
        ]);

        $results = $this->sync->push($request->user(), $validated['items']);

        return $this->success([
            'results' => array_values($results),
            'conflicts' => count(array_filter($results, fn ($result) => $result['status'] === SyncItem::STATUS_CONFLICT)),
        ]);
    }

    /**
     * Conflicting offline items waiting for a manager's review, the same list as the
     * web panel's Sync page.
     */
    public function conflicts(Request $request): JsonResponse
    {
        $this->permit($this->staffUser($request), 'sync.resolve');

        return $this->success([
            'conflicts' => $this->conflictRows(SyncItem::query()->where('status', SyncItem::STATUS_CONFLICT)),
            'recent' => $this->conflictRows(SyncItem::query()->where('status', SyncItem::STATUS_ACCEPTED)->limit(30)),
        ]);
    }

    /**
     * Discard a conflicting offline item after the manager reviewed it.
     */
    public function discardConflict(Request $request, SyncItem $item): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'sync.resolve');

        $item->update(['status' => 'discarded']);
        activity()->performedOn($item)->causedBy($user)->log('sync.conflict_discarded');

        return $this->success(['id' => $item->id, 'status' => 'discarded'], __('sync.conflict_discarded'));
    }

    /**
     * Retry a conflicting item (for example after stock was topped up).
     */
    public function retryConflict(Request $request, SyncItem $item): JsonResponse
    {
        $user = $this->staffUser($request);
        $this->permit($user, 'sync.resolve');

        $result = $this->sync->push($user, [
            ['client_id' => $item->client_id.'-retry-'.time(), 'type' => $item->type, 'payload' => $item->payload],
        ]);

        $outcome = reset($result);

        if ($outcome !== false && $outcome['status'] === SyncItem::STATUS_ACCEPTED) {
            $item->update(['status' => SyncItem::STATUS_ACCEPTED, 'accepted_at' => now(), 'conflict_reason' => null]);

            return $this->success(['id' => $item->id, 'status' => SyncItem::STATUS_ACCEPTED], __('sync.retried'));
        }

        throw ValidationException::withMessages(['item' => __('sync.retry_failed')]);
    }

    /**
     * @param  Builder<SyncItem>  $query
     * @return array<int, array<string, mixed>>
     */
    private function conflictRows(Builder $query): array
    {
        return $query->with('user:id,name')->latest()->limit(100)->get()
            ->map(fn (SyncItem $item): array => [
                'id' => $item->id,
                'type' => $item->type,
                'client_id' => $item->client_id,
                'payload' => $item->payload,
                'status' => $item->status,
                'conflict_reason' => $item->conflict_reason,
                'user' => $item->user?->only(['id', 'name']),
                'created_at' => $item->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
