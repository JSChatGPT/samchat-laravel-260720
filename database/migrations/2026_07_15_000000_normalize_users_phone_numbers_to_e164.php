<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Backfill users.phone_number to canonical E.164 so contact-sync
     * matching (ContactController::sync) works for pre-existing rows
     * stored in inconsistent formats. Numbers without a country code
     * are assumed Zambian (+260), matching PhoneNumber::DEFAULT_DIAL_CODE.
     */
    public function up(): void
    {
        $rows = DB::table('users')->select('id', 'phone_number')->get();

        // Group by normalized value first so we can detect and skip any
        // collisions before writing anything (the unique constraint on
        // phone_number would otherwise abort the whole migration).
        $byNormalized = [];
        foreach ($rows as $row) {
            $normalized = PhoneNumber::toE164($row->phone_number);
            $byNormalized[$normalized][] = $row;
        }

        $updated = 0;
        foreach ($byNormalized as $normalized => $group) {
            if (count($group) > 1) {
                Log::warning('Phone normalization collision, skipped', [
                    'normalized' => $normalized,
                    'user_ids' => array_map(fn ($r) => $r->id, $group),
                    'original_numbers' => array_map(fn ($r) => $r->phone_number, $group),
                ]);
                continue;
            }

            $row = $group[0];
            if ($row->phone_number === $normalized) {
                continue;
            }

            DB::table('users')->where('id', $row->id)->update(['phone_number' => $normalized]);
            $updated++;
        }

        Log::info("Phone number backfill complete: {$updated} row(s) normalized.");
    }

    public function down(): void
    {
        // Not reversible: original raw formats aren't retained.
    }
};
