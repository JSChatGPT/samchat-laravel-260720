<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Group extends Model
{
    use HasUuids;

    protected $primaryKey = 'chat_id';

    protected $fillable = [
        'chat_id',
        'group_name',
        'group_image',
        'created_by_user_id',
        'only_admins_can_post',
        'invite_link_hash',
        'is_active',
    ];

    protected $appends = ['group_image_url'];

    protected $casts = [
        'only_admins_can_post' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function getGroupImageUrlAttribute()
    {
        if ($this->group_image) {
            return asset('storage/' . $this->group_image);
        }
        return null;
    }

    public function chat()
    {
        return $this->belongsTo(Chat::class, 'chat_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
