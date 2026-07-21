<?php

namespace App\Support;

class PhoneNumber
{
    public const DEFAULT_DIAL_CODE = '260';

    /**
     * Canonicalize a phone number to E.164, mirroring the Flutter app's
     * own _toE164 (contacts_sync_notifier.dart) so both sides agree on
     * the same format. Numbers already in E.164 are left untouched;
     * numbers with no country code are assumed local to Zambia.
     */
    public static function toE164(?string $raw, string $defaultDialCode = self::DEFAULT_DIAL_CODE): string
    {
        $stripped = preg_replace('/[^0-9+]/', '', (string) $raw) ?? '';

        if (str_starts_with($stripped, '+')) {
            return $stripped;
        }

        if (str_starts_with($stripped, '00')) {
            return '+' . substr($stripped, 2);
        }

        // Some devices submit contacts with the full country code but no
        // leading '+' (e.g. "260968793843") — treating that as a local
        // trunk number would double-prefix it to "+260260968793843". If
        // the digits already start with the dial code and are long enough
        // to plausibly contain a full subscriber number after it, just add
        // the '+'.
        if ($defaultDialCode !== '' && str_starts_with($stripped, $defaultDialCode) && strlen($stripped) > strlen($defaultDialCode) + 6) {
            return '+' . $stripped;
        }

        $local = str_starts_with($stripped, '0') ? substr($stripped, 1) : $stripped;
        if ($local === '') {
            return $stripped;
        }

        return '+' . $defaultDialCode . $local;
    }
}
