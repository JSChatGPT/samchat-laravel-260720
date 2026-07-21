<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class UserController extends Controller
{
    /**
     * Search for users by phone number or username.
     */
    public function search(Request $request)
    {
        $query = $request->input('q');
        $savedContacts = auth()->user()->savedContacts->pluck('custom_name', 'contact_user_id');
        
        if (!$query) {
            $users = User::where('id', '!=', auth()->id())->limit(50)->get(['id', 'first_name', 'last_name', 'username', 'about_status', 'photo_url']);
        } else {
            $users = User::where('id', '!=', auth()->id())
                ->where(function ($q) use ($query) {
                    $q->where('phone_number', 'like', "%{$query}%")
                      ->orWhere('username', 'like', "%{$query}%");
                })
                ->limit(20)
                ->get(['id', 'first_name', 'last_name', 'username', 'about_status', 'photo_url']);
        }

        foreach ($users as $user) {
            $user->saved_name = $savedContacts[$user->id] ?? null;
        }

        return response()->json(['users' => $users]);
    }

    public function show($user_id)
    {
        $user = User::findOrFail($user_id);
        return response()->json(['user' => $user]);
    }

    public function heartbeat(Request $request)
    {
        $user = $request->user();
        $user->update(['last_seen_at' => now()]);

        // Optional: you could return list of online users here if you track them via DB
        return response()->json(['status' => 'success']);
    }

    public function onlineStatus($user_id)
    {
        $user = User::findOrFail($user_id);

        // Let's say if last_seen_at is within last 2 minutes, they are online
        $isOnline = $user->last_seen_at && $user->last_seen_at->diffInMinutes(now()) <= 2;

        $viewer = auth()->user();
        $hasUnviewedStatus = false;
        if ($viewer && $viewer->id !== $user->id && $viewer->canViewStatusOf($user)) {
            $activeStatusIds = $user->statuses()->where('expires_at', '>', now())->pluck('id');
            if ($activeStatusIds->isNotEmpty()) {
                $viewedStatusIds = \App\Models\StatusView::where('viewer_id', $viewer->id)
                    ->whereIn('status_id', $activeStatusIds)
                    ->pluck('status_id');
                $hasUnviewedStatus = $activeStatusIds->count() > $viewedStatusIds->count();
            }
        }

        return response()->json([
            'is_online' => $isOnline,
            'last_seen_at' => $user->last_seen_at,
            'has_unviewed_status' => $hasUnviewedStatus,
        ]);
    }

    public function getPrivacy(Request $request)
    {
        $user = $request->user();
        $list = $user->status_privacy_list ?? [];
        $usernames = [];
        if (!empty($list)) {
            $usernames = \App\Models\User::whereIn('id', $list)->pluck('username')->toArray();
        }
        return response()->json([
            'status_privacy' => $user->status_privacy ?? 'contacts',
            'status_privacy_list' => $list,
            'status_privacy_usernames' => $usernames
        ]);
    }

    public function updatePrivacy(Request $request)
    {
        $request->validate([
            'status_privacy' => 'required|in:everyone,contacts,selected,exclude',
            'status_privacy_list' => 'nullable|array',
            'status_privacy_list.*' => 'string'
        ]);

        $user = $request->user();
        $user->status_privacy = $request->status_privacy;
        
        $resolvedIds = [];
        if (!empty($request->status_privacy_list)) {
            $resolvedIds = \App\Models\User::whereIn('id', $request->status_privacy_list)
                         ->orWhereIn('username', $request->status_privacy_list)
                         ->pluck('id')
                         ->toArray();
        }
        
        $user->status_privacy_list = $resolvedIds;
        $user->save();

        return response()->json([
            'status_privacy' => $user->status_privacy,
            'status_privacy_list' => $user->status_privacy_list
        ]);
    }
}
