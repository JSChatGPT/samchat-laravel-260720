<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Status;
use Illuminate\Support\Facades\DB;

class StatusController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        // Fetch all statuses that haven't expired
        $statuses = Status::with('user')
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'asc')
            ->get();

        $postersWithCurrentUserInContacts = \App\Models\Contact::where('contact_user_id', $currentUser->id)->pluck('user_id')->toArray();
        $currentUserSavedContacts = $currentUser->savedContacts->pluck('custom_name', 'contact_user_id');

        $filteredStatuses = $statuses->filter(function ($status) use ($currentUser, $postersWithCurrentUserInContacts, $currentUserSavedContacts) {
            $poster = $status->user;

            // Always see own status
            if ($poster->id === $currentUser->id) {
                return true;
            }

            $privacy = $poster->status_privacy ?? 'contacts';
            $privacyList = $poster->status_privacy_list ?? [];

            if ($privacy === 'everyone') {
                return true;
            }

            // "My contacts" (WhatsApp-style): visible only if the save is
            // mutual — the poster has the viewer saved AND the viewer has
            // the poster saved. A one-directional save doesn't count.
            $mutualContact = in_array($poster->id, $postersWithCurrentUserInContacts)
                && $currentUserSavedContacts->has($poster->id);

            if ($privacy === 'contacts') {
                return $mutualContact;
            }

            if ($privacy === 'selected') {
                return in_array($currentUser->id, $privacyList);
            }

            if ($privacy === 'exclude') {
                return $mutualContact && !in_array($currentUser->id, $privacyList);
            }

            return false;
        });

        // `viewed_by_me` was never actually being computed — every status
        // always serialized without it, so clients always fell back to
        // "unviewed" (see StatusItem.fromJson / the status-ring color).
        $viewedStatusIds = \App\Models\StatusView::where('viewer_id', $currentUser->id)
            ->whereIn('status_id', $filteredStatuses->pluck('id'))
            ->pluck('status_id')
            ->all();

        // Group by user and apply saved_name + viewed_by_me
        $groupedStatuses = $filteredStatuses->groupBy('user_id')->map(function ($userStatuses) use ($currentUserSavedContacts, $viewedStatusIds) {
            $userStatuses->each(function ($status) use ($currentUserSavedContacts, $viewedStatusIds) {
                if ($status->user) {
                    $status->user->saved_name = $currentUserSavedContacts[$status->user->id] ?? null;
                }
                $status->viewed_by_me = in_array($status->id, $viewedStatusIds);
            });
            return $userStatuses;
        });

        return response()->json(['statuses' => $groupedStatuses]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|in:text,image,video',
            'content' => 'nullable|string',
            'media' => 'nullable|file|mimes:jpeg,png,jpg,gif,mp4,mov,webm|max:50000', // 50MB max
            'background_color' => 'nullable|string',
        ]);

        $user = $request->user();
        
        $type = $request->type;
        $content = $request->content;
        
        if ($request->hasFile('media')) {
            $file = $request->file('media');
            $mime = $file->getMimeType();
            
            if (str_starts_with($mime, 'video/')) {
                $type = 'video';
            } elseif (str_starts_with($mime, 'image/')) {
                $type = 'image';
            }
            
            $path = $file->store('statuses', 'public');
            $content = asset('storage/' . $path);
        }

        $status = Status::create([
            'user_id' => $user->id,
            'type' => $type,
            'content' => $content,
            'background_color' => $request->background_color,
            'duration' => $type === 'video' ? 15000 : 5000, // 15s for video, 5s for others
            'expires_at' => now()->addHours(24),
        ]);

        return response()->json(['status' => $status->load('user')], 201);
    }

    public function destroy(Request $request, $status_id)
    {
        $user = $request->user();
        $status = Status::where('user_id', $user->id)->findOrFail($status_id);
        
        $status->delete();
        
        return response()->json(['status' => 'success', 'message' => 'Status deleted']);
    }

    public function markViewed(Request $request, $status_id)
    {
        $status = Status::findOrFail($status_id);
        $user = $request->user();

        // Don't record view if viewing own status
        if ($status->user_id !== $user->id) {
            $status->views()->firstOrCreate([
                'viewer_id' => $user->id
            ]);
        }

        return response()->json(['status' => 'success']);
    }

    public function viewers(Request $request, $status_id)
    {
        $user = $request->user();
        $status = Status::where('user_id', $user->id)->findOrFail($status_id);
        
        $viewers = $status->views()->with('viewer')->get();

        return response()->json(['viewers' => $viewers]);
    }
}
