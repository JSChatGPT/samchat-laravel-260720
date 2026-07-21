<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SampayAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'sampay_id',
        'username',
        'mobile_number',
        'access_token',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
