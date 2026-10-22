<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Notification\NotificationFeed;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class NotificationController extends Controller
{
    public function __invoke(Request $request, NotificationFeed $feed): View
    {
        return view('portal.notifications.index', [
            'viewer' => $request->user(),
            'notifications' => $feed->forUser($request->user()),
        ]);
    }
}
