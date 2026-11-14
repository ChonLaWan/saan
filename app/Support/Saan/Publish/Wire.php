<?php

namespace App\Support\Saan\Publish;

/** Shared encoding rules of the push channel, used identically by the builder (internal) and the receiver (public). */
final class Wire
{
    /** Stream that is not a projected table: the list of modules active on the internal side. */
    public const MODULES = 'pub_active_modules';
    /** Key column per stream (default id). */
    public const KEYS = ['pub_certificates' => 'number', 'pub_active_modules' => 'module_id', 'pub_complaint_status' => 'receipt_no', 'pub_complaint_reports' => 'fiscal_year', 'pub_popular_posts' => 'post_id'];
    public const SKEW = 300;
    public const FILE_EXT = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'pdf' => ['application/pdf'],
        'doc' => ['application/msword'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt' => ['application/vnd.ms-powerpoint'], 'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'csv' => ['text/plain', 'text/csv'], 'txt' => ['text/plain'], 'zip' => ['application/zip', 'application/x-zip-compressed'], 'mp4' => ['video/mp4'],
        // only under cms/ (a mourning-ribbon picture); the receiver re-runs SvgSanitizer on it
        'svg' => ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'],
    ];
    /** Relative path a pushed file may have on the receiving disk (media library and staff photos only). */
    public const FILE_PATH = '~^(?:cms|personnel)/\d{1,10}/[A-Za-z0-9-]{8,64}\.[a-z0-9]{2,5}$~D';

    public static function keyColumn(string $stream): string
    {
        return self::KEYS[$stream] ?? 'id';
    }

    public static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Every value as string (or null) so that two databases agree on a row whatever driver types they return. */
    /**
     * Text that came from the other side, made safe for a log line or a status column: control characters (line breaks included) become a
     * space, invalid UTF-8 is scrubbed and the result is cut. Never put a peer-supplied string into a log or an error message without this.
     */
    public static function safe(mixed $v, int $max = 80): string
    {
        $s = is_scalar($v) ? (string) $v : '';
        $s = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', mb_scrub($s, 'UTF-8'));
        return mb_substr(trim($s), 0, max(1, $max));
    }

    /** An error or reply code from the other side: only [a-z_]{1,40} is passed on, anything else is shown as "?". */
    public static function code(mixed $v): string
    {
        return is_string($v) && preg_match('/^[a-z_]{1,40}$/D', $v) ? $v : '?';
    }

    /**
     * Removes the answer key of every question of a survey ("correct", at any depth) from the questions JSON. The public edition needs only the
     * options to show and validate a form; the key would let anybody who reads the public database or a public archive answer every quiz.
     * Idempotent, and an undecodable value becomes an empty list rather than being passed on as it is.
     */
    public static function scrubAnswerKeys(?string $json): ?string
    {
        if ($json === null) return null;
        $v = json_decode($json, true, 64);
        if (!is_array($v)) return '[]';
        $strip = function (array $a) use (&$strip): array {
            $out = [];
            foreach ($a as $k => $x) {
                if ($k === 'correct') continue;
                $out[$k] = is_array($x) ? $strip($x) : $x;
            }
            return $out;
        };
        return self::json($strip($v));
    }

    /**
     * Reads a reply body with a size cap and a wall-clock deadline. Guzzle's own timeout does not cover a streamed body (only an idle timeout does),
     * so a peer that sends one byte at a time would otherwise keep the process alive for ever.
     */
    public static function readCapped(\Psr\Http\Message\StreamInterface $body, int $max, float $seconds = 30.0): string
    {
        $size = $body->getSize();
        if ($size !== null && $size > $max) throw new \RuntimeException('คำตอบจากอีกฝั่งใหญ่เกินกำหนด ('.$size.' ไบต์)');
        $deadline = microtime(true) + $seconds;
        $buf = '';
        while (!$body->eof()) {
            if (microtime(true) > $deadline) throw new \RuntimeException('อีกฝั่งส่งคำตอบช้าเกินกำหนด');
            $buf .= $body->read(65536);
            if (strlen($buf) > $max) throw new \RuntimeException('คำตอบจากอีกฝั่งใหญ่เกินกำหนด');
        }
        return $buf;
    }

    public static function normalize(array $row): array
    {
        $out = [];
        foreach ($row as $c => $v) $out[(string) $c] = $v === null ? null : (is_bool($v) ? ($v ? '1' : '0') : (string) $v);
        ksort($out, SORT_STRING);
        return $out;
    }

    public static function rowHash(array $row): string
    {
        return hash('sha256', self::json(self::normalize($row)));
    }

    /** @param array<string,string> $hashesByKey key => row hash */
    public static function checksum(array $hashesByKey): array
    {
        ksort($hashesByKey, SORT_STRING);
        $h = hash_init('sha256');
        foreach ($hashesByKey as $k => $v) hash_update($h, $k."\t".$v."\n");
        return ['count' => count($hashesByKey), 'sha256' => hash_final($h)];
    }

    public static function payloadHash(array $payload): string
    {
        return hash('sha256', self::json($payload));
    }
}
