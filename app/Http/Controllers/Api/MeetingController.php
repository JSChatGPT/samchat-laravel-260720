<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Meeting;
use App\Models\MeetingInvitee;
use App\Models\Chat;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;

class MeetingController extends Controller
{
    public function __construct(private PushNotificationService $push)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $meetings = Meeting::where('host_id', $user->id)
            ->orWhereHas('invitees', fn ($q) => $q->where('user_id', $user->id))
            ->with(['host', 'invitees.user'])
            ->orderBy('scheduled_at', 'asc')
            ->get();

        return response()->json(['meetings' => $meetings]);
    }

    public function show(Request $request, $meeting_id)
    {
        $user = $request->user();

        $meeting = $this->meetingsForUser($user->id)
            ->with(['host', 'invitees.user', 'chat'])
            ->findOrFail($meeting_id);

        return response()->json(['meeting' => $meeting]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'scheduled_at' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:5|max:480',
            'call_type' => 'nullable|in:audio,video',
            'invitee_ids' => 'required|array|min:1',
            'invitee_ids.*' => 'uuid|exists:users,id',
        ]);

        $user = $request->user();
        $inviteeIds = array_values(array_unique(array_diff($request->invitee_ids, [$user->id])));

        DB::beginTransaction();
        try {
            // A backing group chat is what actually carries the call when the
            // meeting starts (CallController-style chat_id call already knows
            // how to fan out to every participant) and gives attendees a
            // thread to coordinate in before the scheduled time.
            $chat = Chat::create(['chat_type' => 'group']);
            $chat->group()->create([
                'group_name' => $request->title,
                'created_by_user_id' => $user->id,
            ]);
            $participants = [['user_id' => $user->id, 'is_admin' => true]];
            foreach ($inviteeIds as $id) {
                $participants[] = ['user_id' => $id, 'is_admin' => false];
            }
            $chat->participants()->createMany($participants);

            $meeting = Meeting::create([
                'host_id' => $user->id,
                'chat_id' => $chat->id,
                'title' => $request->title,
                'description' => $request->description,
                'call_type' => $request->call_type ?? 'video',
                'scheduled_at' => $request->scheduled_at,
                'duration_minutes' => $request->duration_minutes ?? 30,
            ]);

            foreach ($inviteeIds as $id) {
                MeetingInvitee::create(['meeting_id' => $meeting->id, 'user_id' => $id]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create meeting'], 500);
        }

        $meeting = $meeting->load(['host', 'invitees.user', 'chat']);

        foreach ($meeting->invitees as $invitee) {
            $this->push->sendToUser(
                $invitee->user,
                'Meeting invite',
                "{$user->first_name} invited you to \"{$meeting->title}\"",
                ['type' => 'meeting_invite', 'meeting_id' => $meeting->id]
            );
        }

        return response()->json(['meeting' => $meeting], 201);
    }

    public function respond(Request $request, $meeting_id)
    {
        $request->validate(['status' => 'required|in:accepted,declined']);

        $user = $request->user();
        $invitee = MeetingInvitee::where('meeting_id', $meeting_id)->where('user_id', $user->id)->firstOrFail();
        $invitee->update(['status' => $request->status]);

        return response()->json(['invitee' => $invitee]);
    }

    /**
     * Bookkeeping only — actually starting the call reuses the normal
     * outgoing-call flow (POST /calls with the meeting's chat_id), exactly
     * like any other group call, so there's exactly one place that creates
     * a Call row and broadcasts IncomingCall.
     */
    public function start(Request $request, $meeting_id)
    {
        $user = $request->user();
        $meeting = Meeting::where('host_id', $user->id)->findOrFail($meeting_id);
        $meeting->update(['started_at' => now()]);

        return response()->json(['meeting' => $meeting]);
    }

    public function ics(Request $request, $meeting_id)
    {
        $user = $request->user();
        $meeting = $this->meetingsForUser($user->id)->with('host')->findOrFail($meeting_id);

        $start = $meeting->scheduled_at->utc()->format('Ymd\THis\Z');
        $end = $meeting->scheduled_at->copy()->addMinutes($meeting->duration_minutes)->utc()->format('Ymd\THis\Z');
        $stamp = now()->utc()->format('Ymd\THis\Z');
        $escape = fn ($v) => addcslashes((string) $v, ",;\\") ;

        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//SamChat//Meetings//EN',
            'BEGIN:VEVENT',
            'UID:' . $meeting->id . '@samchat',
            'DTSTAMP:' . $stamp,
            'DTSTART:' . $start,
            'DTEND:' . $end,
            'SUMMARY:' . $escape($meeting->title),
            'DESCRIPTION:' . $escape($meeting->description ?? ''),
            'ORGANIZER;CN=' . $escape($meeting->host->first_name) . ':mailto:' . ($meeting->host->email ?? 'noreply@samchat.app'),
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="meeting.ics"',
        ]);
    }

    private function meetingsForUser(string $userId)
    {
        return Meeting::where('host_id', $userId)
            ->orWhereHas('invitees', fn ($q) => $q->where('user_id', $userId));
    }
}
