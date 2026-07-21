<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Statuses expire 24h after posting (see Status::expires_at) — this deletes
// the row and its uploaded media file once that passes. Hourly is plenty
// granular for a 24h expiry; doesn't need to be exact-to-the-second.
Schedule::command('statuses:prune-expired')->hourly();

// See SendMeetingReminders — every minute so a meeting starting "in ~10
// minutes" is reliably caught within its 9-11 minute window.
Schedule::command('meetings:send-reminders')->everyMinute();

// Polls connected Gmail/Yahoo accounts for new mail (see SyncEmailAccounts).
// 5 minutes balances timeliness against IMAP connection overhead across
// however many accounts are connected.
Schedule::command('emails:sync')->everyFiveMinutes();
