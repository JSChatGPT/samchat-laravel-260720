<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Meeting extends Model
{
    use HasUuids;

    protected $fillable = [
        'host_id',
        'chat_id',
        'title',
        'description',
        'call_type',
        'scheduled_at',
        'duration_minutes',
        'started_at',
        'reminder_sent_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::orderedUuid();
    }

    public function host()
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function chat()
    {
        return $this->belongsTo(Chat::class, 'chat_id');
    }

    public function invitees()
    {
        return $this->hasMany(MeetingInvitee::class);
    }
}
