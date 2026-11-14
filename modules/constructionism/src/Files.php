<?php

namespace Saan\Modules\Constructionism;

use App\Support\Saan\Mutation;
use App\Support\StorageQuota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

/**
 * Project artifacts: a link (https only) or a file on the PRIVATE disk (storage/app/private/constructionism/...).
 * Stored names are generated; the original name stays in the database. The path is never returned by any endpoint;
 * files are only reachable through download(), which re-checks project access.
 */
class Files
{
    public const KINDS = ['doc' => 'เอกสาร', 'image' => 'รูปภาพ', 'video' => 'วิดีโอ', 'code' => 'โปรแกรมหรือโค้ด', 'model' => 'แบบจำลองหรือสิ่งประดิษฐ์', 'other' => 'อื่น ๆ'];
    /** Allowed extensions. No html, svg, php or other active content. */
    public const EXT = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'mp4', 'webm', 'mov', 'mp3', 'wav', 'txt', 'csv', 'md', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'zip', 'sb3', 'sb2', 'py', 'ino', 'stl', '3mf'];

    private const HIDE = ['path'];

    public static function maxBytes(): int
    {
        return max(1, (int) config('saan.storage.max_upload_mb', 10)) * 1048576;
    }

    public static function shapeMany(int $projectId): array
    {
        return Pbl::q('con_artifacts')->where('project_id', $projectId)->orderBy('id')->get()->map(fn ($a) => self::shape($a))->all();
    }

    public static function shape(object $a): array
    {
        return ['id' => (int) $a->id, 'title' => $a->title, 'kind' => $a->kind, 'kind_label' => self::KINDS[$a->kind] ?? $a->kind, 'url' => $a->url,
            'has_file' => $a->path !== null, 'original_name' => $a->original_name, 'size' => (int) $a->size, 'version' => (int) $a->version, 'created_at' => (string) $a->created_at];
    }

    private static function meta(array $d): array
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($d['title'] ?? '')));
        if ($title === '' || mb_strlen($title) > 160) Pbl::invalid('title', 'กรุณากรอกชื่อชิ้นงาน (ไม่เกิน 160 ตัวอักษร)');
        $kind = (string) ($d['kind'] ?? 'other');
        if (!isset(self::KINDS[$kind])) Pbl::invalid('kind', 'ชนิดของชิ้นงานไม่ถูกต้อง');
        return ['title' => $title, 'kind' => $kind];
    }

    public static function add(int $projectId, array $d, ?UploadedFile $file, ?object $user): array
    {
        $p = Pbl::writable($projectId, $user);
        $meta = self::meta($d);
        $url = isset($d['url']) && trim((string) $d['url']) !== '' ? trim((string) $d['url']) : null;
        if (($file === null) === ($url === null)) Pbl::invalid('file', 'ต้องแนบไฟล์หรือใส่ลิงก์อย่างใดอย่างหนึ่ง');
        if ($url !== null) {
            Mutation::insert('con_artifacts', $meta + ['project_id' => $projectId, 'url' => self::url($url), 'created_by' => $user->id], 'pbl.artifact.add', ['project_id' => $projectId, 'type' => 'url']);
            return Pbl::detail($p, $user);
        }
        if (!$file->isValid()) Pbl::invalid('file', 'อัปโหลดไฟล์ไม่สำเร็จ');
        $name = mb_substr(basename(str_replace('\\', '/', (string) $file->getClientOriginalName())), 0, 160);
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXT, true)) Pbl::invalid('file', 'ชนิดไฟล์นี้ไม่รองรับ (รองรับเอกสาร รูป วิดีโอ เสียง ไฟล์บีบอัด และไฟล์โปรแกรมที่ใช้ในห้องเรียน)');
        $size = (int) $file->getSize();
        if ($size <= 0) Pbl::invalid('file', 'ไฟล์ว่างเปล่า');
        if ($size > self::maxBytes()) Pbl::invalid('file', 'ไฟล์ใหญ่เกินกำหนด (สูงสุด '.(self::maxBytes() / 1048576).' MB)');
        StorageQuota::assertRoom($size);
        $sha = hash_file('sha256', $file->getRealPath());
        $path = Storage::disk('local')->putFileAs('constructionism/'.Pbl::school().'/p'.$projectId, $file, Str::random(40).'.'.$ext);
        if ($path === false) abort(500, 'บันทึกไฟล์ไม่สำเร็จ');
        try {
            Mutation::insert('con_artifacts', $meta + ['project_id' => $projectId, 'path' => $path, 'original_name' => $name, 'size' => $size, 'sha256' => $sha, 'created_by' => $user->id], 'pbl.artifact.add', ['project_id' => $projectId, 'type' => 'file', 'bytes' => $size]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        return Pbl::detail($p, $user);
    }

    private static function url(string $url): string
    {
        return UrlRule::check($url);
    }

    public static function update(int $id, array $d, ?object $user): array
    {
        [$a, $p] = Pbl::childOf('con_artifacts', $id, $user);
        $set = self::meta($d);
        if ($a->path === null) $set['url'] = self::url((string) ($d['url'] ?? ''));
        Pbl::safe(fn () => Mutation::update('con_artifacts', $id, (int) $d['version'], $set, 'pbl.artifact.update'), self::HIDE);
        return Pbl::detail($p, $user);
    }

    public static function remove(int $id, int $version, ?object $user): array
    {
        [$a, $p] = Pbl::childOf('con_artifacts', $id, $user);
        Pbl::safe(fn () => Mutation::delete('con_artifacts', $id, $version, 'pbl.artifact.remove'), self::HIDE);
        if ($a->path) Storage::disk('local')->delete($a->path);
        return Pbl::detail($p, $user);
    }

    public static function download(int $id, ?object $user)
    {
        [$a] = Pbl::childOf('con_artifacts', $id, $user, false);
        if (!$a->path || !Storage::disk('local')->exists($a->path)) abort(404, 'ไม่พบไฟล์');
        Mutation::audit('pbl.artifact.download', 'con_artifacts', $id);
        return Storage::disk('local')->download($a->path, $a->original_name ?: 'artifact-'.$id.'.'.pathinfo($a->path, PATHINFO_EXTENSION), [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
