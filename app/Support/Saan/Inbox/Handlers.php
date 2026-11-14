<?php

namespace App\Support\Saan\Inbox;

/**
 * Handlers of the inbox kinds (internal and combined edition). A handler is callable(array $payload, array $files, array $ctx): array and runs in the
 * internal database. It must be idempotent (the same envelope can arrive twice after a lost ack) and throws InboxRejected for a final refusal;
 * any other exception means "try again later" and leaves the envelope on the public side.
 *
 * Built in: contact. The surveys module contributes survey. Later modules register theirs with Handlers::register().
 */
final class Handlers
{
    /** @var array<string,callable> */
    private static array $extra = [];

    public static function register(string $kind, callable $handler): void
    {
        self::$extra[$kind] = $handler;
    }

    public static function forget(): void
    {
        self::$extra = [];
    }

    public static function for(string $kind): ?callable
    {
        if (isset(self::$extra[$kind])) return self::$extra[$kind];
        if ($kind === 'contact') return [new ContactHandler(), 'handle'];
        if ($kind === 'survey' && class_exists(\Saan\Modules\Surveys\InboxHandler::class) && app(\App\Support\Modules\ModuleRegistry::class)->active('surveys')) {
            return [new \Saan\Modules\Surveys\InboxHandler(), 'handle'];
        }
        if ($kind === 'complaint' && class_exists(\Saan\Modules\Complaints\InboxHandler::class) && app(\App\Support\Modules\ModuleRegistry::class)->active('complaints')) {
            return [new \Saan\Modules\Complaints\InboxHandler(), 'handle'];
        }
        // F4: aggregate download counts of hub documents (cumulative per-day totals, no visitor data); web-cms is always active
        if ($kind === 'hub_downloads' && class_exists(\Saan\Modules\WebCms\Downloads::class)) {
            return fn (array $payload, array $files, array $ctx): array => \Saan\Modules\WebCms\Downloads::receive($payload);
        }
        // F5a: aggregate per-day view counts of news posts (cumulative totals, no visitor data)
        if ($kind === 'post_views' && class_exists(\Saan\Modules\WebCms\PostViews::class)) {
            return fn (array $payload, array $files, array $ctx): array => \Saan\Modules\WebCms\PostViews::receive($payload);
        }
        return null;
    }

    /**
     * The payload as the quarantine may keep it. A kind whose payload holds personal words or a secret (complaint) cuts or seals those fields; every other kind keeps
     * the payload as it was. A redactor that fails keeps nothing at all.
     */
    public static function redact(string $kind, array $payload): array
    {
        if ($kind === 'survey' || $kind === 'contact') return self::summary($kind, $payload);
        if ($kind !== 'complaint') return $payload;
        if (!class_exists(\Saan\Modules\Complaints\InboxHandler::class)) return ['redacted' => true]; // module missing on this side: keep nothing of the complaint
        try { return \Saan\Modules\Complaints\InboxHandler::redact($payload); } catch (\Throwable) { return ['redacted' => true]; }
    }

    /**
     * What the quarantine keeps of a survey or contact payload: no value of any field (an anonymous survey must stay anonymous: no e-mail, roster code, answers or IP),
     * only the shape needed to understand the rejection (field names with sizes, slug, subject length). A contact message is additionally sealed with Crypt so the admin can still read it.
     */
    private static function summary(string $kind, array $payload): array
    {
        $sizes = [];
        foreach ($payload as $k => $v) $sizes[mb_substr((string) $k, 0, 40)] = is_scalar($v) ? mb_strlen((string) $v) : strlen((string) json_encode($v));
        $out = ['redacted' => true, 'kind' => $kind, 'fields' => array_slice($sizes, 0, 40)];
        if (isset($payload['slug']) && is_string($payload['slug']) && preg_match('/^[a-z0-9-]{1,80}$/', $payload['slug'])) $out['slug'] = $payload['slug'];
        if (isset($payload['subject']) && is_string($payload['subject'])) $out['subject_length'] = mb_strlen($payload['subject']);
        if ($kind === 'contact') {
            $keep = [];
            foreach (['name', 'email', 'phone', 'subject', 'message'] as $k) if (isset($payload[$k]) && is_scalar($payload[$k])) $keep[$k] = (string) $payload[$k];
            try { $out['sealed'] = \Illuminate\Support\Facades\Crypt::encryptString(json_encode($keep, JSON_UNESCAPED_UNICODE)); } catch (\Throwable) { /* keep the shape only */ }
        }
        return $out;
    }

    public static function dispatch(string $kind, array $payload, array $files = [], array $ctx = []): array
    {
        $h = self::for($kind) ?? throw new InboxRejected('ไม่มีตัวจัดการสำหรับชนิดข้อมูล '.$kind, 422, 'unknown_kind');
        return $h($payload, $files, $ctx);
    }
}
