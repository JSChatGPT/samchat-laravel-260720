<?php

namespace App\Services;

use App\Models\Email;
use App\Models\EmailAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email as MimeEmail;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Message;

/**
 * Talks to a user's Gmail/Yahoo account over plain IMAP (read) + SMTP
 * (send) using an app-specific password they generated themselves — no
 * OAuth app registration/review needed (see the "how other platforms do
 * this" discussion this feature came out of). Real per-user credentials,
 * never stored in plain text (EmailAccount::app_password uses Laravel's
 * `encrypted` cast).
 */
class EmailSyncService
{
    private function imapConfig(EmailAccount $account): array
    {
        return [
            'host' => $account->imap_host,
            'port' => $account->imap_port,
            // The library treats any non-empty string as "encrypted" and
            // only special-cases 'ssl'/'tls'/'starttls' — passing the literal
            // string 'none' happens to fall through to plaintext today, but
            // that's incidental, so 'none' (a real, user-selectable value now
            // that custom providers exist) is translated to `false` here to
            // not depend on that fallthrough.
            'encryption' => $account->imap_encryption === 'none' ? false : $account->imap_encryption,
            'validate_cert' => true,
            'username' => $account->email_address,
            'password' => $account->app_password,
            'protocol' => 'imap',
        ];
    }

    private function connect(EmailAccount $account): Client
    {
        $client = (new ClientManager())->make($this->imapConfig($account));
        $client->connect();
        return $client;
    }

    /**
     * webklex/php-imap only decodes RFC 2047 encoded-word headers
     * (`=?utf-8?q?...?=`) via ext-imap's imap_mime_header_decode(); without
     * that extension loaded it silently falls back to the raw, still-encoded
     * string (verified against a real Gmail message with an emoji subject —
     * ext-imap isn't installed here). mb_decode_mimeheader() is a no-op on
     * plain text, so it's safe to apply unconditionally.
     */
    private function decodeMimeHeader(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        return mb_decode_mimeheader($value);
    }

    /**
     * Attempts a real IMAP login — thrown exceptions propagate to the
     * caller (EmailAccountController) so a bad app password is rejected
     * immediately instead of silently saved.
     *
     * @throws ConnectionFailedException|AuthFailedException
     */
    public function testConnection(EmailAccount $account): void
    {
        $this->connect($account)->disconnect();
    }

    /**
     * Fetches new INBOX messages since the last sync (or the last 3 days on
     * a brand-new account, to avoid pulling a whole mailbox history) and
     * stores them. Returns how many were genuinely new, so the caller can
     * decide whether to push/broadcast. Called on demand (account connect's
     * first inbox open, pull-to-refresh) rather than inline during connect —
     * this is real IMAP network I/O and was previously the direct cause of
     * client-side request timeouts when it ran inline. The 40-message cap
     * keeps even a brand-new/active mailbox's first sync reasonably bounded
     * — a pre-existing tradeoff (previously 100), not new: `last_synced_at`
     * advances to "now" after each run regardless of the cap, so a mailbox
     * with more than 40 messages in the lookback window will have older
     * ones within that window permanently skipped, not caught up later.
     *
     * Returns the newly-created rows (not just a count) so the caller
     * (SyncEmailAccounts) can include a specific email_id in the push
     * payload when exactly one new message came in — enough for the mobile
     * client to offer a one-tap "Reply" action directly from the
     * notification, the same way it can't for an ambiguous "3 new emails"
     * batch.
     */
    public function sync(EmailAccount $account): \Illuminate\Support\Collection
    {
        $client = $this->connect($account);
        $folder = $client->getFolder('INBOX');
        if (!$folder) {
            $client->disconnect();
            return collect();
        }

        $since = $account->last_synced_at ?? now()->subDays(3);
        $messages = $folder->messages()->whereSince($since)->fetchOrderDesc()->limit(40)->get();

        $newEmails = collect();
        foreach ($messages as $message) {
            $uid = (string) $message->getUid();
            $existed = Email::where('email_account_id', $account->id)
                ->where('folder', 'INBOX')
                ->where('uid', $uid)
                ->exists();

            $from = $message->getFrom()->first();

            $emailRow = Email::updateOrCreate(
                ['email_account_id' => $account->id, 'folder' => 'INBOX', 'uid' => $uid],
                [
                    'message_id' => (string) $message->getMessageId(),
                    'from_address' => $from->mail ?? null,
                    'from_name' => $this->decodeMimeHeader($from->personal ?? null),
                    'to_address' => (string) $message->getTo(),
                    'cc_address' => (string) $message->getCc() ?: null,
                    'subject' => $this->decodeMimeHeader((string) $message->getSubject()),
                    'body_text' => $message->getTextBody(),
                    'body_html' => $message->getHTMLBody() ?: null,
                    'is_read' => $message->getFlags()->contains('Seen'),
                    'is_outgoing' => false,
                    'received_at' => $message->getDate()->toDate(),
                ]
            );

            if (!$existed) {
                $newEmails->push($emailRow);
                $this->saveAttachments($emailRow, $message);
            }
        }

        $client->disconnect();
        $account->update(['last_synced_at' => now()]);

        return $newEmails;
    }

    /**
     * Persists a message's real (non-inline) attachments to the 'public'
     * disk — the same disk/convention every other upload feature in this
     * app uses (status media, group photos, chat attachments) — and records
     * one row per file. Only called for newly-created rows (see sync()
     * above) so re-polling an already-synced message never re-saves/
     * duplicates its attachment files.
     */
    private function saveAttachments(Email $emailRow, Message $message): void
    {
        foreach ($message->getAttachments() as $attachment) {
            // Inline parts (e.g. a signature logo referenced via `cid:` in
            // the HTML body) aren't "attachments" from the user's
            // perspective — skip them so they don't clutter the list.
            if (strtolower((string) $attachment->getDisposition()) === 'inline') {
                continue;
            }

            try {
                $content = $attachment->getContent();
                $name = $this->decodeMimeHeader((string) $attachment->getName()) ?: 'attachment';
                $path = "email_attachments/{$emailRow->id}/" . \Illuminate\Support\Str::random(12) . '_' . $name;
                Storage::disk('public')->put($path, $content);

                $emailRow->attachments()->create([
                    'file_name' => $name,
                    'file_path' => $path,
                    'mime_type' => $attachment->getMimeType(),
                    'size_bytes' => strlen($content),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to save email attachment', ['email_id' => $emailRow->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Sends a real email over SMTP and records a local 'SENT' copy.
     * $attachments is an array of ['path' => <public-disk relative path>,
     * 'name' => original filename, 'mime' => mime type, 'size' => bytes] —
     * the caller (EmailController) is expected to have already stored the
     * uploaded files to the 'public' disk before calling this, so sending
     * and persisting the sent record can share the same stored files rather
     * than uploading/storing them twice.
     *
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     */
    public function send(
        EmailAccount $account,
        array $to,
        array $cc,
        string $subject,
        string $body,
        array $attachments = [],
        ?string $inReplyTo = null,
    ): Email {
        // Only the initial socket wrapper is controlled here (true = implicit
        // TLS on connect, like port 465); STARTTLS is negotiated
        // automatically by the transport during EHLO whenever the server
        // advertises it, independent of this flag — so 'tls' and 'none' both
        // correctly resolve to false rather than forcing implicit TLS.
        $tls = match ($account->smtp_encryption) {
            'ssl' => true,
            'tls', 'none' => false,
            default => null,
        };
        $transport = new EsmtpTransport($account->smtp_host, $account->smtp_port, $tls);
        $transport->setUsername($account->email_address);
        $transport->setPassword($account->app_password);

        $mimeEmail = (new MimeEmail())
            ->from($account->email_address)
            ->to(...$to)
            ->subject($subject)
            ->text($body);

        if (!empty($cc)) {
            $mimeEmail->cc(...$cc);
        }

        foreach ($attachments as $attachment) {
            $mimeEmail->attachFromPath(
                Storage::disk('public')->path($attachment['path']),
                $attachment['name'],
                $attachment['mime'] ?? null,
            );
        }

        if ($inReplyTo) {
            // Real mail clients thread a reply to the original message using
            // these two headers — without them a reply just shows up as a
            // brand-new, unrelated message in the recipient's client.
            $mimeEmail->getHeaders()->addIdHeader('In-Reply-To', $inReplyTo);
            $mimeEmail->getHeaders()->addIdHeader('References', $inReplyTo);
        }

        (new Mailer($transport))->send($mimeEmail);

        $emailRow = Email::create([
            'email_account_id' => $account->id,
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'folder' => 'SENT',
            'from_address' => $account->email_address,
            'to_address' => implode(', ', $to),
            'cc_address' => !empty($cc) ? implode(', ', $cc) : null,
            'subject' => $subject,
            'body_text' => $body,
            'is_read' => true,
            'is_outgoing' => true,
            'received_at' => now(),
        ]);

        foreach ($attachments as $attachment) {
            $emailRow->attachments()->create([
                'file_name' => $attachment['name'],
                'file_path' => $attachment['path'],
                'mime_type' => $attachment['mime'] ?? null,
                'size_bytes' => $attachment['size'] ?? 0,
            ]);
        }

        return $emailRow;
    }
}
