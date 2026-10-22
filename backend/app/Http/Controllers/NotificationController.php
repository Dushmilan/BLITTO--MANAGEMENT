<?php

namespace App\Http\Controllers;

use App\Domain\Notification\NotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON parity with the reference SPA's `GET /notifications`. The Blade page
 * reads the feed directly (same process).
 */
final class NotificationController extends Controller
{
    public function __invoke(Request $request, NotificationFeed $feed): JsonResponse
    {
        return response()->json($feed->forUser($request->user()));
    }
}
