<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageReport extends Model
{
    protected $fillable = [
        'message_id',
        'reporter_id',
        'reason',
        'details',
    ];

    public function message()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
