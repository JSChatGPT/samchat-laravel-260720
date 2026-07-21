<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageReceipt extends Model
{
    protected $fillable = [
        'message_id',
        'user_id',
        'status',
        'created_at',
    ];

    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;

    public function message()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
