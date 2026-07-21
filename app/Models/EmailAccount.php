<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class EmailAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'provider',
        'email_address',
        'app_password',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'last_synced_at',
    ];

    protected $casts = [
        // Laravel's built-in AES-256-CBC (APP_KEY) cast — transparent
        // encrypt-on-write / decrypt-on-read, never stored in plain text.
        'app_password' => 'encrypted',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = ['app_password'];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::orderedUuid();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function emails()
    {
        return $this->hasMany(Email::class);
    }

    /**
     * Provider host/port presets — Provider::forProvider() below builds the
     * full IMAP/SMTP connection config for a new account from just these.
     */
    public static function providerDefaults(string $provider): array
    {
        return match ($provider) {
            'gmail' => [
                'imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
                'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            ],
            'yahoo' => [
                'imap_host' => 'imap.mail.yahoo.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
                'smtp_host' => 'smtp.mail.yahoo.com', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            ],
            default => throw new \InvalidArgumentException("Unsupported email provider: {$provider}"),
        };
    }
}
