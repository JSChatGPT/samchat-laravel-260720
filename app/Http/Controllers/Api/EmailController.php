<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Email;
use App\Services\EmailSyncService;

class EmailController extends Controller
{
    public function __construct(private EmailSyncService $emailSync)
    {
    }

    public function index(Request $request, $accountId)
    {
        $account = $request->user()->emailAccounts()->findOrFail($accountId);

        $emails = $account->emails()
            ->withCount('attachments')
            ->orderByDesc('received_at')
            ->paginate(30);

        return response()->json(['emails' => $emails]);
    }

    public function show(Request $request, $emailId)
    {
        $email = Email::whereHas('account', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('attachments')
            ->findOrFail($emailId);

        if (!$email->is_read) {
            $email->update(['is_read' => true]);
        }

        return response()->json(['email' => $email]);
    }

    /**
     * Parses a comma/semicolon-separated address list (what the "To"/"Cc"
     * text fields on both clients send) into a deduplicated array of valid
     * addresses. Returns null if any entry isn't a valid address — the
     * caller turns that into a clean 422 rather than a confusing SMTP
     * rejection later. An empty/blank field returns an empty array (valid
     * for the optional Cc field).
     */
    private function parseAddressList(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $addresses = collect(preg_split('/[,;]/', $raw))
            ->map(fn ($a) => trim($a))
            ->filter()
            ->unique()
            ->values();

        if ($addresses->contains(fn ($a) => !filter_var($a, FILTER_VALIDATE_EMAIL))) {
            return null;
        }

        return $addresses->all();
    }

    /**
     * Stores uploaded attachment files to the 'public' disk — the same
     * disk/convention every other upload feature in this app uses (status
     * media, group photos, chat attachments) — and returns the metadata
     * EmailSyncService::send() needs to both attach them to the outgoing
     * SMTP message and record EmailAttachment rows against the sent copy.
     */
    private function storeUploadedAttachments(Request $request): array
    {
        $stored = [];
        foreach ($request->file('attachments', []) as $file) {
            if (!$file->isValid()) {
                continue;
            }
            $path = $file->store('email_attachments/outgoing', 'public');
            $stored[] = [
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ];
        }
        return $stored;
    }

    public function send(Request $request, $accountId)
    {
        $request->validate([
            'to' => 'required|string',
            'cc' => 'nullable|string',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:15360',
        ]);

        $to = $this->parseAddressList($request->to);
        $cc = $this->parseAddressList($request->cc);
        if (empty($to) || $cc === null) {
            return response()->json(['error' => 'Enter one or more valid email addresses.'], 422);
        }

        $account = $request->user()->emailAccounts()->findOrFail($accountId);
        $attachments = $this->storeUploadedAttachments($request);

        try {
            $email = $this->emailSync->send($account, $to, $cc, $request->subject, $request->body, $attachments);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to send: ' . $e->getMessage()], 422);
        }

        return response()->json(['status' => 'success', 'email' => $email->load('attachments')], 201);
    }

    public function reply(Request $request, $emailId)
    {
        $request->validate([
            'body' => 'required|string',
            'reply_all' => 'nullable|boolean',
            'cc' => 'nullable|string',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:15360',
        ]);

        $email = Email::whereHas('account', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('account')
            ->findOrFail($emailId);

        if (empty($email->from_address)) {
            return response()->json(['error' => 'This message has no sender to reply to.'], 422);
        }

        $extraCc = $this->parseAddressList($request->cc);
        if ($extraCc === null) {
            return response()->json(['error' => 'Enter one or more valid email addresses.'], 422);
        }

        $to = [$email->from_address];
        $cc = $extraCc;

        if ($request->boolean('reply_all')) {
            // Reply-all = original sender (already in $to) + everyone else
            // who was on the original To/Cc lines, minus this mailbox
            // itself and the sender (avoids CC'ing yourself or double-
            // addressing the sender). The stored to_address/cc_address are
            // "Display Name" <email> pairs straight from the original
            // message and real display names legitimately contain commas
            // (e.g. `"'Phiri, Tom'" <tom@x.com>`, confirmed against a real
            // synced email) — naively exploding on ',' shatters those, so
            // addresses are pulled out with a regex instead of a split.
            $myAddress = strtolower($email->account->email_address);
            $senderAddress = strtolower($email->from_address);
            preg_match_all('/[^\s<>",]+@[^\s<>",]+\.[^\s<>",]+/', $email->to_address . ' ' . ($email->cc_address ?? ''), $matches);
            $others = collect($matches[0])
                ->map(fn ($a) => strtolower(trim($a, '.')))
                ->filter(fn ($a) => $a !== $myAddress && $a !== $senderAddress);
            $cc = collect($cc)->merge($others)->unique()->values()->all();
        }

        $subject = str_starts_with(strtolower($email->subject ?? ''), 're:')
            ? $email->subject
            : 'Re: ' . $email->subject;

        $attachments = $this->storeUploadedAttachments($request);

        try {
            $sent = $this->emailSync->send(
                $email->account,
                $to,
                $cc,
                $subject,
                $request->body,
                $attachments,
                $email->message_id ?: null,
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to send: ' . $e->getMessage()], 422);
        }

        return response()->json(['status' => 'success', 'email' => $sent->load('attachments')], 201);
    }
}
