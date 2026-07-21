<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ChatParticipant extends Model
{
    protected $table = 'chat_participants';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = [
        'chat_id',
        'user_id',
        'is_muted',
        'unread_count',
        'last_read_message_id',
        'is_admin',
    ];

    protected $casts = [
        'is_muted' => 'boolean',
        'is_admin' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
