<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmailAttachment extends Model
{
    use HasUuids;

    protected $fillable = [
        'email_id',
        'file_name',
        'file_path',
        'mime_type',
        'size_bytes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    protected $appends = ['url'];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::orderedUuid();
    }

    public function email()
    {
        return $this->belongsTo(Email::class);
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/' . $this->file_path);
    }
}
