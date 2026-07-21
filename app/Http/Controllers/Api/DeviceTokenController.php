<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'platform' => 'required|in:ios,android,web',
        ]);

        $request->user()->deviceTokens()->firstOrCreate([
            'token' => $request->token,
        ], [
            'platform' => $request->platform,
        ]);

        return response()->json(['message' => 'Device token registered successfully']);
    }

    public function destroy(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $request->user()->deviceTokens()->where('token', $request->token)->delete();

        return response()->json(['message' => 'Device token removed successfully']);
    }
}
