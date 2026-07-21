<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;

/**
 * Pushes a reminder to every invitee ~10 minutes before a meeting starts.
 * Scheduled every minute in routes/console.php; reminder_sent_at stops a
 * meeting from being reminded twice across ticks.
 */
class SendMeetingReminders extends Command
{
    protected $signature = 'meetings:send-reminders';

    protected $description = 'Send a push reminder for meetings starting in ~10 minutes';

    public function handle(PushNotificationService $push): int
    {
        $due = Meeting::whereNull('reminder_sent_at')
            ->whereNull('started_at')
            ->whereBetween('scheduled_at', [now()->addMinutes(9), now()->addMinutes(11)])
            ->with('invitees.user')
            ->get();

        foreach ($due as $meeting) {
            foreach ($meeting->invitees as $invitee) {
                $push->sendToUser(
                    $invitee->user,
                    'Meeting starting soon',
                    "\"{$meeting->title}\" starts in 10 minutes",
                    ['type' => 'meeting_reminder', 'meeting_id' => $meeting->id]
                );
            }
            $meeting->update(['reminder_sent_at' => now()]);
        }

        $this->info("Sent reminders for {$due->count()} meeting(s).");

        return self::SUCCESS;
    }
}
