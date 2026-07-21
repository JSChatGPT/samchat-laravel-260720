<?php

namespace App\Console\Commands;

use App\Events\NewEmailReceived;
use App\Models\EmailAccount;
use App\Services\EmailSyncService;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Polls every connected Gmail/Yahoo account over IMAP for new mail. Plain
 * polling (no IMAP IDLE/push) since a scheduled command is simpler to run
 * reliably than holding hundreds of persistent IMAP connections open.
 * Scheduled every 5 minutes in routes/console.php.
 */
class SyncEmailAccounts extends Command
{
    protected $signature = 'emails:sync';

    protected $description = 'Sync new mail for every connected email account';

    public function handle(EmailSyncService $emailSync, PushNotificationService $push): int
    {
        $accounts = EmailAccount::with('user')->get();
        $synced = 0;

        foreach ($accounts as $account) {
            try {
                $newEmails = $emailSync->sync($account);
                $newCount = $newEmails->count();
                $synced++;

                if ($newCount > 0) {
                    broadcast(new NewEmailReceived($account->user_id, $account->id, $newCount));
                    $push->sendToUser(
                        $account->user,
                        'New email',
                        $newCount === 1
                            ? "You have a new email in {$account->email_address}"
                            : "You have {$newCount} new emails in {$account->email_address}",
                        [
                            'type' => 'new_email',
                            'email_account_id' => $account->id,
                            // Only unambiguous when it's a single new message — a
                            // batch of several shouldn't offer a one-tap "Reply"
                            // aimed at just one of them.
                            ...($newCount === 1 ? ['email_id' => $newEmails->first()->id] : []),
                        ]
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Email sync failed', ['account_id' => $account->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Synced {$synced}/{$accounts->count()} email account(s).");

        return self::SUCCESS;
    }
}
