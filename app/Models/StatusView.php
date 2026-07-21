<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatusView extends Model
{
    protected $fillable = [
        'status_id',
        'viewer_id',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function status()
    {
        return $this->belongsTo(Status::class);
    }

    public function viewer()
    {
        return $this->belongsTo(User::class, 'viewer_id');
    }
}
