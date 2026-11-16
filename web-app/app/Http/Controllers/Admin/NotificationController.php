<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Services\StaffAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Mark every alert in the bell as read for the signed-in person. An alert shows again when its
     * text changes, for example when more ingredients run low.
     */
    public function markAllRead(Request $request, HandleInertiaRequests $page): RedirectResponse
    {
        $user = $request->user('staff');

        app(StaffAlerts::class)->markAllRead($user, $page->currentBranchId($user));

        return back();
    }
}
