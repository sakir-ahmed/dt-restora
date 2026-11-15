<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Preorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeletePreorderAction
{
    /**
     * Delete a pre-order taken by mistake. There is no stock or ledger behind it, so the row is
     * simply kept marked as deleted, like the other safe-deleted records.
     */
    public function execute(Preorder $preorder, ?User $user = null, ?string $reason = null, ?string $note = null): Preorder
    {
        return DB::transaction(function () use ($preorder, $user, $reason, $note): Preorder {
            $preorder = Preorder::query()->whereKey($preorder->id)->lockForUpdate()->firstOrFail();

            $preorder->forceFill(['deleted_by' => $user?->id, 'delete_reason' => $reason, 'delete_note' => $note])->save();
            $preorder->delete();

            activity()->performedOn($preorder)->causedBy($user)->withProperties(['reason' => $reason, 'note' => $note])->log('preorder.deleted');

            return $preorder;
        });
    }
}
