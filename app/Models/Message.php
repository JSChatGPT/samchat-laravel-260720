<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Message extends Model
{
    use HasUuids;

    protected $fillable = [
        'chat_id',
        'sender_id',
        'message_type',
        'content',
        'metadata',
        'quoted_message_id',
        'is_forwarded',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_forwarded' => 'boolean',
    ];

    // `receipts` was always eager-loadable but never surfaced as a simple
    // flag — every client (mobile + web) checks `is_read`/`read_at` on the
    // message itself, not the raw `receipts` array, so without these the
    // read tick only ever updated live (via the MessagesRead broadcast,
    // while that exact chat happened to be open) and reverted back to
    // "sent" on every reload since neither GET /chats nor GET /chats/{id}
    // ever actually persisted read status into the response.
    protected $appends = ['is_read', 'read_at'];

    // For a direct chat this is exactly right (one other participant). For
    // a group, this goes true as soon as *any* member has read it — the
    // client only renders a single tick state per message, so there's no
    // "read by 3 of 8" UI to feed a more granular signal into yet.
    public function getIsReadAttribute(): bool
    {
        return $this->receipts->contains(fn ($r) => $r->status === 'read');
    }

    public function getReadAtAttribute(): ?string
    {
        $receipt = $this->receipts->firstWhere('status', 'read');
        if (!$receipt || !$receipt->created_at) {
            return null;
        }
        // MessageReceipt sets $timestamps = false, so unlike Message's own
        // created_at, this one is never auto-cast to Carbon — it's still
        // the raw DB string here.
        return \Illuminate\Support\Carbon::parse($receipt->created_at)->toISOString();
    }

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::orderedUuid();
    }

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receipts()
    {
        return $this->hasMany(MessageReceipt::class, 'message_id');
    }

    public function quotedMessage()
    {
        return $this->belongsTo(Message::class, 'quoted_message_id');
    }

    public function reactions()
    {
        return $this->hasMany(MessageReaction::class, 'message_id');
    }
}
