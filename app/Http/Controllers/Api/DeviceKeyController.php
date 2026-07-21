<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UserDeviceKey;

class DeviceKeyController extends Controller
{
    /**
     * Register (or rotate) this device's E2EE public key. Called once on
     * first run per device — the private key never leaves the client.
     */
    public function store(Request $request)
    {
        $request->validate([
            'device_id' => 'required|string|max:255',
            'public_key' => 'required|string',
            'platform' => 'required|in:android,ios,web',
        ]);

        $user = $request->user();

        $key = UserDeviceKey::updateOrCreate(
            ['user_id' => $user->id, 'device_id' => $request->device_id],
            ['public_key' => $request->public_key, 'platform' => $request->platform]
        );

        return response()->json(['device_key' => $key], 201);
    }

    /**
     * Every current public key for a user, across all their devices — used
     * by whoever creates/adds-to a chat to seal a new chat key to each of
     * the other participant's devices.
     */
    public function forUser(Request $request, $user_id)
    {
        $keys = UserDeviceKey::where('user_id', $user_id)
            ->get(['device_id', 'public_key', 'platform']);

        return response()->json(['device_keys' => $keys]);
    }
}
