<?php

namespace Saan\Modules\WebCms;

use App\Support\Saan\Mutation;
use App\Support\Saan\Publish\Wire;
use App\Support\StorageQuota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files of hub documents go through the media library (cms_media, private local disk, same path rules as every other media file).
 * Only PDF, Office documents and pictures are accepted, and the type comes from the CONTENT (finfo), never from the name or the browser.
 */
final class HubFiles
{
    /** Extensions a hub document may have; the MIME list of each comes from Wire::FILE_EXT (shared with the publisher and the inbox). */
    public const EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'webp'];
    public const MAX_FILES = 20;
    public const FOLDER = 'เอกสารกลุ่มงาน';
    private const OOXML = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'];

    public static function maxBytes(): int
    {
        return max(1, (int) config('saan.storage.max_upload_mb', 20)) * 1048576;
    }

    /** True for a MIME type that a hub document may be (used again when serving). */
    public static function allowedMime(string $mime): bool
    {
        foreach (self::EXT as $e) if (in_array($mime, Wire::FILE_EXT[$e], true)) return true;
        return false;
    }

    /**
     * Checks one upload without storing it. Aborts 422 with the file name in the message.
     * @return array{ext:string,mime:string,size:int}
     */
    public static function inspect(UploadedFile $f): array
    {
        $label = mb_substr((string) $f->getClientOriginalName(), 0, 60);
        abort_unless($f->isValid(), 422, "อัปโหลดไฟล์ไม่สำเร็จ: $label");
        $size = (int) $f->getSize();
        abort_if($size < 1, 422, "ไฟล์ว่างเปล่า: $label");
        abort_if($size > self::maxBytes(), 422, "ไฟล์ใหญ่เกินกำหนด (สูงสุด ".intdiv(self::maxBytes(), 1048576)." MB): $label");
        $ext = strtolower((string) $f->getClientOriginalExtension());
        abort_unless(in_array($ext, self::EXT, true), 422, "รับเฉพาะไฟล์ PDF, Word, Excel, PowerPoint และรูปภาพ: $label");
        $path = (string) $f->getRealPath();
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $ok = in_array($mime, Wire::FILE_EXT[$ext], true);
        // some libmagic builds report an OOXML file as a plain zip: accept that only when the zip really holds the part of that Office format
        if (!$ok && isset(self::OOXML[$ext]) && in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
            $ok = self::ooxml($path, $ext);
            if ($ok) $mime = Wire::FILE_EXT[$ext][0];
        }
        abort_unless($ok, 422, "ชนิดไฟล์ไม่ตรงกับนามสกุล: $label");
        if (str_starts_with($mime, 'image/')) {
            $dim = @getimagesize($path);
            abort_unless($dim, 422, "อ่านข้อมูลภาพไม่ได้: $label");
            abort_if($dim[0] > 6000 || $dim[1] > 6000, 422, "ภาพต้องไม่เกิน 6000 x 6000 จุด: $label");
        }
        return ['ext' => $ext === 'jpeg' ? 'jpg' : $ext, 'mime' => $mime, 'size' => $size];
    }

    private static function ooxml(string $path, string $ext): bool
    {
        if (!class_exists(\ZipArchive::class)) return false;
        $z = new \ZipArchive();
        if ($z->open($path) !== true) return false;
        $has = $z->locateName('[Content_Types].xml') !== false;
        $part = false;
        for ($i = 0; $i < min($z->numFiles, 400) && $has; $i++) if (str_starts_with((string) $z->getNameIndex($i), self::OOXML[$ext])) { $part = true; break; }
        $z->close();
        return $has && $part;
    }

    /**
     * Stores an inspected upload in the media library. Returns the media id and the public download name (original name cleaned, extension forced to the sniffed type).
     * @param array{ext:string,mime:string,size:int} $info from inspect()
     * @return array{media_id:int,file_name:string,title:string}
     */
    public static function store(UploadedFile $f, array $info): array
    {
        $school = Hubs::school();
        StorageQuota::assertRoom($school, $info['size']);
        $path = 'cms/'.$school.'/'.Str::uuid().'.'.$info['ext'];
        abort_unless(Storage::disk('local')->put($path, (string) file_get_contents((string) $f->getRealPath())), 500, 'บันทึกไฟล์ไม่สำเร็จ');
        $orig = self::cleanName((string) $f->getClientOriginalName());
        $stem = pathinfo($orig, PATHINFO_FILENAME);
        $name = mb_substr($stem !== '' ? $stem : 'document', 0, 150).'.'.$info['ext'];
        $w = $h = 0;
        if (str_starts_with($info['mime'], 'image/')) [$w, $h] = array_slice((array) getimagesize((string) $f->getRealPath()), 0, 2);
        try {
            $id = DB::transaction(function () use ($school, $path, $info, $w, $h, $orig) {
                $id = (int) DB::table('cms_media')->insertGetId(['school_id' => $school, 'uploaded_by' => auth()->id(), 'path' => $path, 'mime' => $info['mime'], 'bytes' => $info['size'],
                    'width' => $w, 'height' => $h, 'alt' => '', 'original_name' => $orig, 'folder' => self::FOLDER, 'created_at' => now(), 'updated_at' => now()]);
                Mutation::audit('media.uploaded', 'cms_media', $id); // same event as the media library upload
                return $id;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        return ['media_id' => $id, 'file_name' => $name, 'title' => mb_substr(trim((string) preg_replace('/[\s_]+/u', ' ', $stem)) ?: 'เอกสาร', 0, 200)];
    }

    /** No directories, no control characters, at most 180 characters. */
    public static function cleanName(string $raw): string
    {
        $n = basename(str_replace('\\', '/', $raw));
        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', $n), 0, 180);
    }

    /** Removes a media row (and its file) that no document, post, theme or other content uses any more. */
    public static function release(?int $mediaId): void
    {
        if (!$mediaId) return;
        $m = DB::table('cms_media')->where('id', $mediaId)->where('school_id', Hubs::school())->first();
        if (!$m || $m->folder !== self::FOLDER) return; // only files this feature stored itself
        if (DB::table('cms_hub_documents')->where('media_id', $mediaId)->exists()) return;
        if (app(\App\Http\Controllers\WebsiteMediaController::class)->used($mediaId, Hubs::school(), false)) return;
        DB::transaction(function () use ($mediaId) {
            DB::table('cms_media')->where('id', $mediaId)->delete();
            Mutation::audit('media.deleted', 'cms_media', $mediaId);
        });
        Storage::disk('local')->delete($m->path);
    }

    /** A validated link in its stored form: scheme and host in lower case, so the LIKE 'https://%' filters and the strict download check always agree. */
    public static function normalizeLink(string $url): string
    {
        return (string) preg_replace_callback('~^https://([^/?\#]*)~i', fn ($m) => 'https://'.strtolower($m[1]), $url);
    }

    /** A stored external link: https only, a real host, no credentials, no spaces. Never taken from a request at download time. */
    public static function validLink(?string $url): bool
    {
        if (!is_string($url) || $url === '' || strlen($url) > 500 || preg_match('/[\s\x00-\x1F\x7F]/', $url)) return false;
        $p = parse_url($url);
        if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return false;
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        return str_contains($p['host'], '.') && !str_ends_with($p['host'], '.') && !preg_match('/^(localhost|.*\.local)$/i', $p['host']);
    }
}
