<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Mark every alert in the bell as read for the signed-in person. An alert shows again when its
     * text changes, for example when more ingredients run low.
     */
    public function markAllRead(Request $request, HandleInertiaRequests $alerts): RedirectResponse
    {
        $user = $request->user('staff');

        $user->forceFill([
            'notifications_read' => collect($alerts->currentNotifications($user))
                ->mapWithKeys(fn (array $item): array => [$item['id'] => HandleInertiaRequests::fingerprint($item)])
                ->all(),
        ])->save();

        return back();
    }
}
