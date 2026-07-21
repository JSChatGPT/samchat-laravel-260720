<?php

namespace App\Console\Commands;

use App\Models\Status;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Statuses already stop being *shown* once expires_at passes (see
 * StatusController::index()'s `where('expires_at', '>', now())`), but the
 * row and its uploaded media file stick around on disk forever unless
 * something prunes them — this is that something. Scheduled hourly in
 * routes/console.php.
 */
class PruneExpiredStatuses extends Command
{
    protected $signature = 'statuses:prune-expired';

    protected $description = 'Delete expired statuses (24h after posting) and their stored media files';

    public function handle(): int
    {
        $expired = Status::where('expires_at', '<=', now())->get();

        $deletedFiles = 0;
        foreach ($expired as $status) {
            if (in_array($status->type, ['image', 'video'], true) && $status->content) {
                // StatusController::store() stores the uploaded asset's public
                // URL directly in `content` (no separate media_url column) —
                // recover the disk-relative path from it regardless of the
                // current APP_URL/host, since that can drift (see AppConfig
                // comments on the mobile client about this dev box's LAN IP).
                $path = Str::after($status->content, '/storage/');
                if ($path !== $status->content && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                    $deletedFiles++;
                }
            }
            $status->delete();
        }

        $this->info("Pruned {$expired->count()} expired status(es), removed {$deletedFiles} media file(s).");

        return self::SUCCESS;
    }
}
