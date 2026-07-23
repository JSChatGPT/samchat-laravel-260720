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
