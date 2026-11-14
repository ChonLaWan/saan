<?php

namespace Saan\Modules\Registry;

use App\Support\Saan\Mutation;
use App\Support\StorageQuota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Identity-document store. Files live on the private local disk (storage/app/private/registry/<school>/<student>/...),
 * never under public/. Only registrars reach these methods (route role check). Downloads are audited.
 */
class DocumentService
{
    public const KINDS = ['birth_certificate', 'house_registration', 'passport', 'transfer_record', 'photo', 'other'];
    public const REVIEW = ['pending', 'accepted', 'rejected'];
    private const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private RegistryRepository $repo) {}

    private function disk() { return Storage::disk('local'); }


    public function upload(int $studentId, UploadedFile $file, string $kind, ?string $label): array
    {
        $this->repo->student($studentId);
        $school = $this->repo->school();
        $max = max(1, (int) config('saan.storage.max_upload_mb')) * 1048576;
        abort_unless($file->isValid(), 422, 'อัปโหลดไฟล์ไม่สำเร็จ');
        $size = (int) $file->getSize();
        abort_if($size < 1, 422, 'ไฟล์ว่างเปล่า');
        abort_if($size > $max, 422, 'ไฟล์ใหญ่เกิน '.round($max / 1048576).' MB');
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()); // sniffed from content, never from the client-supplied name or type
        abort_unless(isset(self::MIMES[$mime]), 422, 'รองรับเฉพาะไฟล์ PDF, JPG, PNG และ WebP');
        // school-wide total (media library + every module's storage_tables, reg_documents included): only the new bytes are passed
        StorageQuota::assertRoom($size);
        $sha = hash_file('sha256', $file->getRealPath());
        $path = 'registry/'.$school.'/'.$studentId.'/'.bin2hex(random_bytes(16)).'.'.self::MIMES[$mime];
        $stream = fopen($file->getRealPath(), 'rb');
        try { abort_unless($this->disk()->put($path, $stream), 500, 'บันทึกไฟล์ไม่สำเร็จ'); } finally { if (is_resource($stream)) fclose($stream); }
        try {
            $row = Mutation::insert('reg_documents', [
                'student_id' => $studentId, 'kind' => $kind, 'label' => trim((string) $label) ?: $kind, 'path' => $path, 'mime' => $mime, 'size' => $size,
                'sha256' => $sha, 'review_state' => 'pending', 'uploaded_by' => auth()->id(),
            ], 'registry.document.uploaded', ['student_id' => $studentId, 'kind' => $kind, 'size' => $size]);
        } catch (\Throwable $e) {
            $this->disk()->delete($path);
            throw $e;
        }
        return $this->repo->presentDocument($row);
    }

    public function find(int $id): object
    {
        $d = DB::table('reg_documents')->where('school_id', $this->repo->school())->where('id', $id)->first() ?? abort(404, 'ไม่พบเอกสาร');
        $this->repo->student((int) $d->student_id); // 404 when the student is outside this school or scope
        return $d;
    }

    public function download(int $id)
    {
        $d = $this->find($id);
        abort_unless($this->disk()->exists($d->path), 404, 'ไม่พบไฟล์เอกสาร');
        Mutation::audit('registry.document.downloaded', 'reg_documents', (int) $d->id, ['student_id' => (int) $d->student_id, 'kind' => $d->kind]);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $d->kind.'-'.$d->id).'.'.(self::MIMES[$d->mime] ?? 'bin');
        return $this->disk()->download($d->path, $name, ['Content-Type' => $d->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function review(int $id, int $version, array $d): array
    {
        $cur = $this->find($id);
        $ch = [];
        if (isset($d['label'])) $ch['label'] = trim($d['label']);
        if (isset($d['kind'])) $ch['kind'] = $d['kind'];
        if (isset($d['review_state'])) { $ch['review_state'] = $d['review_state']; $ch['reviewed_by'] = $d['review_state'] === 'pending' ? null : auth()->id(); }
        $row = $this->repo->guard(fn () => Mutation::update('reg_documents', $id, $version, $ch, 'registry.document.reviewed', ['student_id' => (int) $cur->student_id, 'fields' => array_keys($ch)]), ['path']);
        return $this->repo->presentDocument($row);
    }

    public function delete(int $id, int $version): void
    {
        $d = $this->find($id);
        $this->repo->guard(fn () => Mutation::delete('reg_documents', $id, $version, 'registry.document.deleted'), ['path']);
        $this->disk()->delete($d->path);
    }
}
