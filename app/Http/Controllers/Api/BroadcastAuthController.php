<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class BroadcastAuthController extends Controller
{
    public function authenticate(Request $request)
    {
        $request->validate([
            'channel_name' => 'required|string',
            'socket_id' => 'required|string',
        ]);

        try {
            return Broadcast::auth($request);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Broadcast authentication failed'], 403);
        }
    }
}
