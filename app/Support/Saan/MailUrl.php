<?php

namespace App\Support\Saan;

/**
 * Absolute links inside e-mails are built from APP_URL, never from the request Host header,
 * so a forged Host on a request that triggers a mail (password reset, invite, notices) cannot
 * point the recipient to another site.
 */
class MailUrl
{
    public static function to(string $path): string
    {
        $base = rtrim((string) config('app.url'), '/');
        if ($base === '' || !preg_match('~^https?://~i', $base)) return url($path);
        return $base.'/'.ltrim($path, '/');
    }
}
