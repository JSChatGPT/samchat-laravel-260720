<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\HasApiTokens;
use App\Support\PhoneNumber;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids;

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'phone_number',
        'username',
        'photo_url',
        'thumb_img',
        'about_status',
        'is_blocked',
        'last_seen_at',
        'status_privacy',
        'status_privacy_list',
        'is_admin',
    ];

    protected $casts = [
        'is_blocked' => 'boolean',
        'last_seen_at' => 'datetime',
        'status_privacy_list' => 'array',
        'is_admin' => 'boolean',
    ];

    public function newUniqueId()
    {
        return (string) \Illuminate\Support\Str::orderedUuid();
    }

    /**
     * Always store phone numbers in canonical E.164 form so contact-sync
     * matching (ContactController::sync) can rely on a plain string compare.
     */
    public function setPhoneNumberAttribute($value): void
    {
        $this->attributes['phone_number'] = PhoneNumber::toE164($value);
    }

    public function chats()
    {
        return $this->belongsToMany(Chat::class, 'chat_participants', 'user_id', 'chat_id')
                    ->withPivot('is_muted', 'unread_count', 'last_read_message_id', 'is_admin');
    }

    public function messages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function deviceTokens()
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function savedContacts()
    {
        return $this->hasMany(Contact::class, 'user_id');
    }

    public function sampayAccount()
    {
        return $this->hasOne(SampayAccount::class);
    }

    public function emailAccounts()
    {
        return $this->hasMany(EmailAccount::class);
    }

    public function statuses()
    {
        return $this->hasMany(Status::class);
    }

    /**
     * Same privacy rule StatusController::index() applies when listing
     * statuses, extracted here so a single-user lookup (UserController::
     * onlineStatus) doesn't have to duplicate it. Not used by index() itself,
     * which bulk-computes contact lists once for all posters instead of
     * running two queries per poster.
     */
    public function canViewStatusOf(User $poster): bool
    {
        if ($this->id === $poster->id) {
            return true;
        }

        // Bidirectional — mirrors StatusController::index's identical check,
        // which this method deliberately doesn't get called by (see its own
        // doc comment) but must still agree with for any other caller.
        $isBlocked = \App\Models\BlockedUser::where(function ($q) use ($poster) {
            $q->where('blocker_id', $this->id)->where('blocked_id', $poster->id);
        })->orWhere(function ($q) use ($poster) {
            $q->where('blocker_id', $poster->id)->where('blocked_id', $this->id);
        })->exists();
        if ($isBlocked) {
            return false;
        }

        $privacy = $poster->status_privacy ?? 'contacts';
        $privacyList = $poster->status_privacy_list ?? [];

        if ($privacy === 'everyone') {
            return true;
        }

        $posterSavedMe = Contact::where('user_id', $poster->id)->where('contact_user_id', $this->id)->exists();
        $iSavedPoster = Contact::where('user_id', $this->id)->where('contact_user_id', $poster->id)->exists();
        $mutualContact = $posterSavedMe && $iSavedPoster;

        if ($privacy === 'contacts') {
            return $mutualContact;
        }

        if ($privacy === 'selected') {
            return in_array($this->id, $privacyList);
        }

        if ($privacy === 'exclude') {
            return $mutualContact && !in_array($this->id, $privacyList);
        }

        return false;
    }
}
