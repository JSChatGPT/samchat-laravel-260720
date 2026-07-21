<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class UserDeviceKey extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'device_id',
        'public_key',
        'platform',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::orderedUuid();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
