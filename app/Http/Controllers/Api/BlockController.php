<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BlockedUser;
use App\Models\User;

class BlockController extends Controller
{
    public function index(Request $request)
    {
        $blockedUsers = BlockedUser::where('blocker_id', $request->user()->id)
            ->with('blocked')
            ->get();
            
        return response()->json(['blocked_users' => $blockedUsers]);
    }

    public function block(Request $request, $user_id)
    {
        $blocker_id = $request->user()->id;

        if ($blocker_id === $user_id) {
            return response()->json(['error' => 'You cannot block yourself'], 400);
        }

        BlockedUser::firstOrCreate([
            'blocker_id' => $blocker_id,
            'blocked_id' => $user_id
        ]);

        return response()->json(['status' => 'success']);
    }

    public function unblock(Request $request, $user_id)
    {
        $blocker_id = $request->user()->id;

        BlockedUser::where('blocker_id', $blocker_id)
            ->where('blocked_id', $user_id)
            ->delete();

        return response()->json(['status' => 'success']);
    }
}
