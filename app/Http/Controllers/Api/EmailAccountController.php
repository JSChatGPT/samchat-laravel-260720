<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EmailAccount;
use App\Services\EmailSyncService;
use Illuminate\Support\Facades\Log;

class EmailAccountController extends Controller
{
    public function __construct(private EmailSyncService $emailSync)
    {
    }

    public function index(Request $request)
    {
        // withCount so the client can show a per-account badge and sum
        // across accounts for a single Email tab/icon badge, without an
        // extra request.
        $accounts = $request->user()->emailAccounts()
            ->withCount(['emails as unread_count' => function ($query) {
                $query->where('is_read', false)->where('is_outgoing', false);
            }])
            ->get();
        return response()->json(['email_accounts' => $accounts]);
    }

    /**
     * Links a Gmail/Yahoo account (host/port presets from
     * EmailAccount::providerDefaults) or a "custom" IMAP/SMTP mailbox where
     * the user supplies their own server details — any provider that isn't
     * one of the two presets (a work email, self-hosted server, etc). Either
     * way, an app-specific password is used, not the account's real login
     * password, and the credentials are validated with a real IMAP login
     * attempt before anything is saved, so a typo'd password/host fails
     * immediately instead of silently sitting broken.
     */
    public function store(Request $request)
    {
        $request->validate([
            'provider' => 'required|in:gmail,yahoo,custom',
            'email_address' => 'required|email',
            'app_password' => 'required|string',
            'imap_host' => 'required_if:provider,custom|string',
            'imap_port' => 'required_if:provider,custom|integer|min:1|max:65535',
            'imap_encryption' => 'required_if:provider,custom|in:ssl,tls,starttls,none',
            'smtp_host' => 'required_if:provider,custom|string',
            'smtp_port' => 'required_if:provider,custom|integer|min:1|max:65535',
            'smtp_encryption' => 'required_if:provider,custom|in:ssl,tls,none',
        ]);

        $user = $request->user();
        $defaults = $request->provider === 'custom'
            ? [
                'imap_host' => $request->imap_host,
                'imap_port' => $request->imap_port,
                'imap_encryption' => $request->imap_encryption,
                'smtp_host' => $request->smtp_host,
                'smtp_port' => $request->smtp_port,
                'smtp_encryption' => $request->smtp_encryption,
            ]
            : EmailAccount::providerDefaults($request->provider);

        $attributes = array_merge($defaults, [
            'user_id' => $user->id,
            'provider' => $request->provider,
            'email_address' => $request->email_address,
            'app_password' => $request->app_password,
        ]);

        // Re-linking an already-connected address (e.g. the app password was
        // rotated, or the user just retried) updates the existing row instead
        // of insert()-ing a second one, which would hit the unique index on
        // (user_id, email_address). Filling the existing model in memory and
        // testing before save() means a bad new password never overwrites a
        // working saved one.
        $existing = $user->emailAccounts()->where('email_address', $request->email_address)->first();
        $account = $existing ? tap($existing)->fill($attributes) : new EmailAccount($attributes);

        try {
            $this->emailSync->testConnection($account);
        } catch (\Throwable $e) {
            // The library throws several different exception types for what
            // is, from the user's perspective, one of two outcomes — bad
            // credentials or an unreachable server — and which class shows
            // up isn't reliably predictable (verified: a real Gmail auth
            // rejection throws ImapServerErrorException, not AuthFailedException
            // as the class name would suggest), so this inspects the message
            // instead of trying to enumerate every exception subclass.
            Log::info('Email account connection test failed', ['provider' => $request->provider, 'error' => $e->getMessage()]);
            $message = str_contains(strtoupper($e->getMessage()), 'AUTHENTICATIONFAILED') || str_contains(strtolower($e->getMessage()), 'credentials')
                ? 'Could not sign in — check the email address and app password.'
                : 'Could not connect to the mail server. Please try again.';
            return response()->json(['error' => $message], 422);
        }

        $account->save();

        // Deliberately no inline sync here. Fetching real message bodies
        // over IMAP used to run inline right before responding so the inbox
        // wasn't empty on first open — but that's slow network I/O on top
        // of the connection test just above (measured: ~2.3s for the
        // connect/login round trip alone, before a single message), and it
        // easily exceeded the mobile app's 30s request timeout — the actual
        // cause of the "connection timeout" reports on both custom and
        // preset providers. The client fetches messages via a separate
        // POST .../sync call (see sync() below) when it opens the inbox,
        // so a slow mailbox never blocks "Connect".
        return response()->json(['email_account' => $account->fresh()], $existing ? 200 : 201);
    }

    /**
     * Fetches new mail for one account on demand — called by the client
     * when opening an account's inbox and on pull-to-refresh, deliberately
     * kept separate from store() so this endpoint's latency (real IMAP I/O)
     * never blocks the "Connect" request.
     */
    public function sync(Request $request, $id)
    {
        $account = $request->user()->emailAccounts()->findOrFail($id);

        try {
            $newEmails = $this->emailSync->sync($account);
        } catch (\Throwable $e) {
            Log::warning('Manual email sync failed', ['account_id' => $account->id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Could not sync — check your connection and try again.'], 422);
        }

        return response()->json(['new_count' => $newEmails->count()]);
    }

    public function destroy(Request $request, $id)
    {
        $account = $request->user()->emailAccounts()->findOrFail($id);
        $account->delete();
        return response()->json(['status' => 'success']);
    }
}
