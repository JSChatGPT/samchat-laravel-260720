<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Email extends Model
{
    use HasUuids;

    protected $fillable = [
        'email_account_id',
        'uid',
        'folder',
        'message_id',
        'from_address',
        'from_name',
        'to_address',
        'cc_address',
        'subject',
        'body_text',
        'body_html',
        'is_read',
        'is_outgoing',
        'received_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'is_outgoing' => 'boolean',
        'received_at' => 'datetime',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::orderedUuid();
    }

    public function account()
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function attachments()
    {
        return $this->hasMany(EmailAttachment::class);
    }
}
