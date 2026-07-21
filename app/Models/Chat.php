<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Chat extends Model
{
    use HasUuids;

    protected $fillable = [
        'chat_type',
        'last_message_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::orderedUuid();
    }

    public function participants()
    {
        return $this->hasMany(ChatParticipant::class, 'chat_id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function group()
    {
        return $this->hasOne(Group::class, 'chat_id', 'id');
    }
}
