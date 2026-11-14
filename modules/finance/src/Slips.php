<?php

namespace Saan\Modules\Finance;

use App\Support\StorageQuota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Upload handling for payment slips and bank QR images (private disk only, never a public URL).
 * - the type comes from the file CONTENT (finfo + getimagesize / %PDF- header), never from the client name or MIME; SVG and everything else is refused
 * - images are re-encoded with GD (drops EXIF/GPS and any appended payload) and limited to MAX_EDGE px; PDF slips are stored as uploaded
 *   but refused when a plain scan (after decoding #xx name escapes) finds JavaScript, launch or embedded files. That scan is a courtesy, NOT the defence:
 *   it cannot see inside compressed object streams. The real protection is how the file is served (see respond()).
 * - sha256 is taken from the uploaded bytes, so the same file sent twice is recognised
 * - files are served only by an authorised controller through response(), with nosniff and a sandboxing CSP
 */
final class Slips
{
    public const IMAGE_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    public const MAX_EDGE = 2000;
    public const MAX_PIXELS = 40000000;
    public const MAX_MB = 5;
    public const MAX_FILES = 3;
    /** The one answer for a refused slip: it must not reveal that the file already exists or belongs to somebody else. */
    public const NEUTRAL = 'ไม่สามารถรับไฟล์นี้ได้ กรุณาตรวจสอบไฟล์สลิปหรือติดต่อโรงเรียน';

    public static function maxBytes(): int
    {
        return min(self::MAX_MB, max(1, (int) config('saan.storage.max_upload_mb', 10))) * 1048576;
    }

    private static function school(): int { return (int) config('school.school_id'); }

    /**
     * Validates one upload and returns what is needed to store it.
     * @return array{mime:string,ext:string,bytes:string,size:int,sha256:string}
     */
    public static function inspect(UploadedFile $file, bool $allowPdf): array
    {
        abort_unless($file->isValid(), 422, 'อัปโหลดไฟล์ไม่สำเร็จ');
        $path = (string) $file->getRealPath();
        $size = (int) $file->getSize();
        abort_if($size < 1, 422, 'ไฟล์ว่างเปล่า');
        abort_if($size > self::maxBytes(), 422, 'ไฟล์ใหญ่เกิน '.round(self::maxBytes() / 1048576).' MB');
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $sha = hash_file('sha256', $path);
        if ($mime === 'application/pdf') {
            abort_unless($allowPdf, 422, 'รองรับเฉพาะรูป PNG, JPEG และ WebP');
            $raw = (string) file_get_contents($path);
            abort_unless(str_starts_with($raw, '%PDF-'), 422, 'ไฟล์ PDF ไม่ถูกต้อง');
            // names may be written with hex escapes (/J#61vaScript), so decode them before looking; /OpenAction and /AA are normal in bank PDFs and are not refused
            $plain = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($m) => chr(hexdec($m[1])), $raw);
            abort_if(preg_match('#/(JavaScript|JS|Launch|EmbeddedFile)(?![A-Za-z0-9])#', $plain) === 1, 422, 'ไฟล์ PDF มีส่วนที่ระบบไม่รับ');
            return ['mime' => $mime, 'ext' => 'pdf', 'bytes' => $raw, 'size' => strlen($raw), 'sha256' => $sha];
        }
        $info = @getimagesize($path);
        abort_unless(isset(self::IMAGE_TYPES[$mime]) && $info !== false && ($info['mime'] ?? '') === $mime, 422, $allowPdf ? 'รองรับเฉพาะรูป PNG, JPEG, WebP หรือไฟล์ PDF' : 'รองรับเฉพาะรูป PNG, JPEG และ WebP');
        $bin = self::clean($path, $mime);
        return ['mime' => $mime, 'ext' => self::IMAGE_TYPES[$mime], 'bytes' => $bin, 'size' => strlen($bin), 'sha256' => $sha];
    }

    private static function clean(string $file, string $mime): string
    {
        abort_unless(function_exists('imagecreatefromstring'), 500, 'เซิร์ฟเวอร์ไม่รองรับการประมวลผลรูป');
        $info = @getimagesize($file);
        abort_if(!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS, 422, 'ขนาดรูปไม่เหมาะสม');
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit !== '' && $limit !== '-1') {
            $n = (int) $limit;
            $bytes = match (strtolower(substr($limit, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
            abort_if($bytes > 0 && $info[0] * $info[1] * 5 > $bytes - memory_get_usage(), 422, 'รูปมีความละเอียดสูงเกินไป กรุณาลดขนาดรูปแล้วลองใหม่');
        }
        $orientation = 1;
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) $orientation = (int) (@exif_read_data($file)['Orientation'] ?? 1);
        $img = @imagecreatefromstring((string) file_get_contents($file));
        abort_unless($img !== false, 422, 'อ่านไฟล์รูปไม่ได้');
        $w = imagesx($img); $h = imagesy($img);
        if (max($w, $h) > self::MAX_EDGE) {
            $scale = self::MAX_EDGE / max($w, $h);
            $small = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
            if ($small !== false) { imagedestroy($img); $img = $small; }
        }
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0 && ($rot = imagerotate($img, $angle, 0)) !== false) { imagedestroy($img); $img = $rot; }
        ob_start();
        try {
            if ($mime === 'image/png') { imagealphablending($img, false); imagesavealpha($img, true); $ok = imagepng($img, null, 6); }
            elseif ($mime === 'image/webp') { imagealphablending($img, false); imagesavealpha($img, true); $ok = function_exists('imagewebp') && imagewebp($img, null, 85); }
            else $ok = imagejpeg($img, null, 85);
        } finally { $out = (string) ob_get_clean(); imagedestroy($img); }
        abort_unless($ok && $out !== '', 422, 'ประมวลผลรูปไม่สำเร็จ');
        return $out;
    }

    /** Writes the inspected bytes to the private disk and returns the relative path. */
    public static function put(array $info, string $kind): string
    {
        StorageQuota::assertRoom($info['size']);
        $path = 'finance/'.self::school().'/'.$kind.'/'.bin2hex(random_bytes(16)).'.'.$info['ext'];
        abort_unless(Storage::disk('local')->put($path, $info['bytes']), 500, 'บันทึกไฟล์ไม่สำเร็จ');
        return $path;
    }

    /** Deletes a stored file; only paths this module wrote (finance/<school>/...) are ever touched. */
    public static function forget(?string $path): void
    {
        if ($path && str_starts_with($path, 'finance/'.self::school().'/') && !str_contains($path, '..')) Storage::disk('local')->delete($path);
    }

    /** Authorised download: the caller has already checked who may see this file. */
    public static function respond(?string $path, string $mime, string $name)
    {
        abort_unless($path && str_starts_with($path, 'finance/'.self::school().'/') && !str_contains($path, '..') && Storage::disk('local')->exists($path), 404);
        $type = in_array($mime, array_keys(self::IMAGE_TYPES), true) || $mime === 'application/pdf' ? $mime : 'application/octet-stream';
        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => $type, 'Content-Disposition' => ($type === 'application/pdf' ? 'attachment' : 'inline').'; filename="'.$name.'"', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private', 'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
            'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
