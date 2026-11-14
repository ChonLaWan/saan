<?php

namespace Saan\Modules\Projects\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use App\Support\StorageQuota;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Saan\Modules\Projects\Support;

/**
 * Progress / final reports with an optional evidence file (PDF, JPEG or PNG, type decided from the bytes, max 10 MB).
 * Bytes live on the private local disk: projects/<school>/<project>/<random>.<ext>; the path and hash never reach the browser.
 * Only the project owner and managers can download evidence.
 */
class ReportController
{
    public const MAX_BYTES = 10485760;
    private const MIME = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    public static function listFor(int $projectId, bool $evidence): array
    {
        $rows = DB::table('prj_reports as r')->leftJoin('users as u', 'u.id', '=', 'r.submitted_by')->where('r.school_id', Support::school())->where('r.project_id', $projectId)
            ->orderByDesc('r.submitted_at')->orderByDesc('r.id')->get(['r.*', 'u.name as submitter']);
        return $rows->map(fn ($x) => self::present($x, $evidence))->all();
    }

    private static function present(object $x, bool $evidence): array
    {
        return ['id' => (int) $x->id, 'project_id' => (int) $x->project_id, 'summary' => $x->summary, 'outcome_pct' => $x->outcome_pct === null ? null : (int) $x->outcome_pct,
            'submitted_by' => (int) $x->submitted_by, 'submitter' => $x->submitter ?? null, 'submitted_at' => $x->submitted_at,
            'has_evidence' => $x->evidence_path !== null, 'evidence_name' => $evidence ? $x->evidence_name : null, 'evidence_size' => $evidence && $x->evidence_size !== null ? (int) $x->evidence_size : null,
            'version' => (int) $x->version];
    }

    private static function sniff(string $path): ?string
    {
        $fh = @fopen($path, 'rb'); if (!$fh) return null;
        $head = (string) fread($fh, 8); fclose($fh);
        $type = str_starts_with($head, '%PDF-') ? 'pdf' : (str_starts_with($head, "\xFF\xD8\xFF") ? 'jpg' : (str_starts_with($head, "\x89PNG\r\n\x1A\n") ? 'png' : null));
        // the magic bytes and libmagic must agree: the extension and Content-Type of the download come from this detected type only
        return $type !== null && (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path) === self::MIME[$type] ? $type : null;
    }

    private static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $name))));
        if (mb_strlen($name) > 190) $name = mb_substr($name, 0, 190);
        return $name !== '' ? $name : 'evidence';
    }

    /** Download name = sanitised stem of what the uploader called it + the extension of the DETECTED type (never the client's extension: x.pdf.html). */
    private static function downloadName(string $name, string $type): string
    {
        $stem = pathinfo(self::cleanName($name), PATHINFO_FILENAME);
        $stem = trim((string) preg_replace('/[^\p{L}\p{N}_ -]+/u', '_', $stem), ' _-');
        return ($stem !== '' ? mb_substr($stem, 0, 150) : 'evidence').'.'.(isset(self::MIME[$type]) ? $type : 'bin');
    }

    public function store(Request $r, int $projectId)
    {
        $d = $r->validate(['summary' => 'required|string|max:5000', 'outcome_pct' => 'nullable|integer|between:0,100']);
        $file = $r->file('evidence');
        $meta = null;
        if ($r->hasFile('evidence') || $file !== null) {
            if (!$file instanceof UploadedFile || !$file->isValid()) abort(422, 'อัปโหลดไฟล์หลักฐานไม่สำเร็จ ไฟล์อาจใหญ่เกินที่เซิร์ฟเวอร์รับได้');
            $size = (int) $file->getSize();
            abort_if($size < 1, 422, 'ไฟล์หลักฐานว่างเปล่า');
            abort_if($size > self::MAX_BYTES, 422, 'ไฟล์หลักฐานต้องไม่เกิน 10 MB');
            $type = self::sniff($file->getRealPath());
            abort_if($type === null, 422, 'หลักฐานรองรับเฉพาะ PDF, JPEG, PNG (ตรวจจากเนื้อไฟล์จริง)');
            $meta = ['type' => $type, 'size' => $size, 'path' => $file->getRealPath(), 'name' => self::downloadName($file->getClientOriginalName(), $type), 'sha' => hash_file('sha256', $file->getRealPath())];
        }
        return Idempotency::run($r, function () use ($d, $projectId, $meta, $r) {
            $stored = null;
            try {
                $row = Support::tx(function () use ($d, $projectId, $meta, $r, &$stored) {
                    [$project, $plan] = Support::context($projectId);
                    Support::assertCan($project, $plan, 'report');
                    $values = ['project_id' => $projectId, 'summary' => $d['summary'], 'outcome_pct' => $d['outcome_pct'] ?? null, 'submitted_by' => $r->user()->id, 'submitted_at' => now()];
                    if ($meta) {
                        StorageQuota::assertRoom($meta['size']);
                        if ($stored === null) {
                            $stored = 'projects/'.Support::school().'/'.$projectId.'/'.bin2hex(random_bytes(16)).'.'.$meta['type'];
                            $stream = fopen($meta['path'], 'rb');
                            try { abort_unless(Support::disk()->put($stored, $stream), 500, 'บันทึกไฟล์ไม่สำเร็จ'); } finally { if (is_resource($stream)) fclose($stream); }
                        }
                        $values += ['evidence_path' => $stored, 'evidence_name' => $meta['name'], 'evidence_mime' => self::MIME[$meta['type']], 'evidence_size' => $meta['size'], 'evidence_sha256' => $meta['sha']];
                    }
                    return Mutation::insert('prj_reports', $values, 'projects.report.created', ['project_id' => $projectId, 'outcome_pct' => $d['outcome_pct'] ?? null, 'evidence' => $meta !== null, 'size' => $meta['size'] ?? 0]);
                });
            } catch (\Throwable $e) {
                if ($stored !== null) Support::disk()->delete($stored);
                throw $e;
            }
            $x = DB::table('prj_reports as r')->leftJoin('users as u', 'u.id', '=', 'r.submitted_by')->where('r.id', $row->id)->first(['r.*', 'u.name as submitter']);
            return response()->json(self::present($x, true), 201);
        });
    }

    /** 409 whose body never carries the private evidence path or hash. */
    private function assertVersion(int $id, int $version): void
    {
        $cur = DB::table('prj_reports')->where('school_id', Support::school())->where('id', $id)->lockForUpdate()->first() ?? abort(404, 'ไม่พบรายงาน');
        if ((int) $cur->version !== $version) throw new \App\Support\Saan\VersionConflict(array_diff_key((array) $cur, array_flip(['evidence_path', 'evidence_sha256'])));
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate(['summary' => 'required|string|max:5000', 'outcome_pct' => 'nullable|integer|between:0,100', 'version' => 'required|integer|min:1']);
        $x = DB::table('prj_reports')->where('school_id', Support::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบรายงาน');
        $row = Support::tx(function () use ($d, $id, $x) {
            [$project, $plan] = Support::context((int) $x->project_id);
            Support::assertCan($project, $plan, 'report');
            $this->assertVersion($id, (int) $d['version']);
            return Mutation::update('prj_reports', $id, (int) $d['version'], ['summary' => $d['summary'], 'outcome_pct' => $d['outcome_pct'] ?? null], 'projects.report.updated');
        });
        $y = DB::table('prj_reports as r')->leftJoin('users as u', 'u.id', '=', 'r.submitted_by')->where('r.id', $row->id)->first(['r.*', 'u.name as submitter']);
        return response()->json(self::present($y, true));
    }

    public function destroy(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $x = DB::table('prj_reports')->where('school_id', Support::school())->where('id', $id)->first() ?? abort(404, 'ไม่พบรายงาน');
        Support::tx(function () use ($d, $id, $x) {
            [$project, $plan] = Support::context((int) $x->project_id);
            Support::assertCan($project, $plan, 'report');
            $this->assertVersion($id, (int) $d['version']);
            Mutation::delete('prj_reports', $id, (int) $d['version'], 'projects.report.deleted');
        });
        if ($x->evidence_path) Support::disk()->delete($x->evidence_path); // the row (and its audit trail) is gone; release the bytes
        return response()->json(['ok' => true]);
    }

    public function evidence(int $id)
    {
        $x = DB::table('prj_reports')->where('school_id', Support::school())->where('id', $id)->first();
        $project = $x ? DB::table('prj_projects')->where('school_id', Support::school())->where('id', $x->project_id)->first() : null;
        // a missing report and a report the user may not see look identical
        abort_unless($x && $project && $x->evidence_path && Support::canSeeEvidence($project), 404, 'ไม่พบไฟล์หลักฐาน');
        abort_unless(Support::disk()->exists($x->evidence_path), 404, 'ไม่พบไฟล์ในที่เก็บ');
        if (!Support::isOwner($project)) Mutation::audit('projects.report.evidence_downloaded', 'prj_reports', (int) $x->id, ['project_id' => (int) $x->project_id]);
        return Support::disk()->download($x->evidence_path, self::downloadName((string) $x->evidence_name, strtolower(pathinfo((string) $x->evidence_path, PATHINFO_EXTENSION))), ['Content-Type' => $x->evidence_mime, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }
}
