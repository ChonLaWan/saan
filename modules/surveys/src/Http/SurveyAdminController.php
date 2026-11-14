<?php

namespace Saan\Modules\Surveys\Http;

use App\Support\Saan\Mutation;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Saan\Modules\Surveys\SurveyService;

/** Admin API. Routes are wrapped in auth + CurrentSchoolAccount + role:admin,principal,editor. */
class SurveyAdminController
{
    private const SLUG = '/^[a-z0-9][a-z0-9-]{1,78}[a-z0-9]$/D';

    public function __construct(private SurveyService $svc) {}

    private function row(object $s, ?int $responses = null): array
    {
        return [
            'id' => $s->id, 'slug' => $s->slug, 'title' => $s->title, 'intro' => (string) $s->intro, 'questions' => $this->svc->questions($s),
            'identity_mode' => $s->identity_mode, 'opens_at' => SurveyService::isoTime($s->opens_at), 'closes_at' => SurveyService::isoTime($s->closes_at),
            'max_responses' => $s->max_responses === null ? null : (int) $s->max_responses, 'certificate' => json_decode((string) ($s->certificate ?? ''), true),
            'anon_ip_hour' => (int) ($s->anon_ip_hour ?? SurveyService::ANON_PER_IP_HOUR), 'locked' => $responses === null ? $this->isLocked($s) : null,
            'status' => $s->status, 'version' => (int) $s->version, 'responses' => $responses ?? DB::table('srv_responses')->where('survey_id', $s->id)->count(),
            'closed_reason' => $this->svc->closedReason($s),
        ];
    }

    /** Structure/identity are frozen once a response exists or a verification link is still waiting to be used. */
    private function isLocked(object $s): bool
    {
        return DB::table('srv_responses')->where('survey_id', $s->id)->exists()
            || DB::table('srv_pending')->where('survey_id', $s->id)->where('expires_at', '>', now())->exists();
    }

    public function index()
    {
        $school = $this->svc->school();
        $counts = DB::table('srv_responses')->join('srv_surveys', 'srv_surveys.id', '=', 'srv_responses.survey_id')->where('srv_surveys.school_id', $school)
            ->groupBy('srv_responses.survey_id')->selectRaw('srv_responses.survey_id as sid, count(*) as n')->pluck('n', 'sid');
        $rows = DB::table('srv_surveys')->where('school_id', $school)->orderByDesc('id')->get()
            ->map(fn ($s) => collect($this->row($s, (int) ($counts[$s->id] ?? 0)))->except(['questions', 'intro', 'locked'])->all() + ['question_count' => count($this->svc->questions($s))]);
        return response()->json(['data' => $rows]);
    }

    public function read(int $id) { return response()->json($this->row($this->svc->find($id))); }

    public function create(Request $r)
    {
        $d = $r->validate([
            'title' => 'required|string|max:200', 'slug' => ['nullable', 'string', 'regex:'.self::SLUG],
            'identity_mode' => 'nullable|in:'.implode(',', SurveyService::MODES),
        ]);
        $slug = $d['slug'] ?? 'survey-'.bin2hex(random_bytes(4));
        $this->assertSlugFree($slug);
        try {
            $s = Mutation::insert('srv_surveys', [
                'slug' => $slug, 'title' => trim($d['title']), 'intro' => null, 'questions' => [], 'identity_mode' => $d['identity_mode'] ?? 'anonymous', 'status' => 'draft',
            ], 'survey.created', ['slug' => $slug]);
        } catch (QueryException $e) {
            if (SurveyService::isUniqueViolation($e)) abort(409, 'มีแบบสอบถามที่ใช้ที่อยู่ลิงก์นี้แล้ว');
            throw $e;
        }
        return response()->json($this->row($s, 0), 201);
    }

    private function assertSlugFree(string $slug, ?int $exceptId = null): void
    {
        $q = DB::table('srv_surveys')->where('school_id', $this->svc->school())->where('slug', $slug);
        if ($exceptId) $q->where('id', '!=', $exceptId);
        abort_if($q->exists(), 409, 'มีแบบสอบถามที่ใช้ที่อยู่ลิงก์นี้แล้ว');
    }

    public function save(Request $r, int $id)
    {
        $survey = $this->svc->find($id);
        $d = $r->validate([
            'version' => 'required|integer|min:1', 'title' => 'required|string|max:200', 'slug' => ['nullable', 'string', 'regex:'.self::SLUG],
            'intro' => 'nullable|string|max:4000', 'identity_mode' => 'required|in:'.implode(',', SurveyService::MODES),
            'opens_at' => 'nullable|date|before:2100-01-01', 'closes_at' => 'nullable|date|before:2100-01-01', 'anon_ip_hour' => 'nullable|integer|min:1|max:100000', 'max_responses' => 'nullable|integer|min:1|max:1000000',
            'questions' => 'present|array|max:60', 'questions.*' => 'array:key,type,label,options,required,correct,max',
            'questions.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/D', 'distinct'],
            'questions.*.type' => 'required|in:'.implode(',', SurveyService::TYPES), 'questions.*.label' => 'required|string|max:500',
            'questions.*.options' => 'nullable|array|max:30', 'questions.*.options.*' => 'string|max:200',
            'questions.*.required' => 'nullable|boolean', 'questions.*.correct' => 'nullable', 'questions.*.correct.*' => 'string|max:200', 'questions.*.max' => 'nullable|integer|between:3,10',
            'certificate' => 'nullable|array:enabled,title,body_template,signer_name,signer_title,min_score',
            'certificate.enabled' => 'nullable|boolean', 'certificate.title' => 'nullable|string|max:200', 'certificate.body_template' => 'nullable|string|max:1000',
            'certificate.signer_name' => 'nullable|string|max:160', 'certificate.signer_title' => 'nullable|string|max:160', 'certificate.min_score' => 'nullable|integer|between:0,100',
        ]);
        $questions = $this->svc->normaliseQuestions($d['questions']);
        $opens = SurveyService::dbTime($d['opens_at'] ?? null);
        $closes = SurveyService::dbTime($d['closes_at'] ?? null);
        if ($opens && $closes && $closes <= $opens) throw ValidationException::withMessages(['closes_at' => 'เวลาปิดต้องหลังเวลาเปิด']);

        $cert = null;
        if (!empty($d['certificate']['enabled'])) {
            $c = $d['certificate'];
            if ($d['identity_mode'] === 'anonymous') throw ValidationException::withMessages(['certificate.enabled' => 'เกียรติบัตรต้องใช้โหมดกรอกชื่อ-อีเมลหรือรหัสรายชื่อ เพราะต้องรู้ชื่อผู้รับ']);
            if (trim((string) ($c['title'] ?? '')) === '') throw ValidationException::withMessages(['certificate.title' => 'กรุณาระบุหัวเกียรติบัตร']);
            $hasScoring = collect($questions)->contains(fn ($q) => SurveyService::hasCorrect($q));
            if (($c['min_score'] ?? null) !== null && !$hasScoring) throw ValidationException::withMessages(['certificate.min_score' => 'ต้องกำหนดเฉลยอย่างน้อยหนึ่งข้อก่อนตั้งคะแนนขั้นต่ำ']);
            $cert = [
                'enabled' => true, 'title' => trim($c['title']),
                'body_template' => trim((string) ($c['body_template'] ?? '')) ?: 'ขอมอบเกียรติบัตรฉบับนี้ให้ไว้เพื่อแสดงว่า {name} ได้ตอบ{survey}เรียบร้อยแล้ว',
                'signer_name' => trim((string) ($c['signer_name'] ?? '')), 'signer_title' => trim((string) ($c['signer_title'] ?? '')),
                'min_score' => $c['min_score'] ?? null,
            ];
        }

        $slug = $d['slug'] ?? $survey->slug;
        $locked = $this->isLocked($survey);
        if ($slug !== $survey->slug) {
            if ($survey->status !== 'draft') throw ValidationException::withMessages(['slug' => 'เปลี่ยนที่อยู่ลิงก์ได้เฉพาะขณะเป็นฉบับร่าง']);
            $this->assertSlugFree($slug, $survey->id);
        }
        if ($locked) {
            if ($d['identity_mode'] !== $survey->identity_mode) throw ValidationException::withMessages(['identity_mode' => 'มีผู้ตอบหรือมีลิงก์ยืนยันที่ยังไม่หมดอายุ เปลี่ยนวิธีระบุตัวตนไม่ได้']);
            if ($this->svc->structure($questions) !== $this->svc->structure($this->svc->questions($survey))) {
                throw ValidationException::withMessages(['questions' => 'มีผู้ตอบหรือมีลิงก์ยืนยันที่ยังไม่หมดอายุ แก้ได้เฉพาะข้อความคำถาม ไม่ให้เพิ่ม/ลบ/เปลี่ยนชนิดหรือตัวเลือก']);
            }
        }
        try {
            $row = Mutation::update('srv_surveys', $id, (int) $d['version'], [
                'slug' => $slug, 'title' => trim($d['title']), 'intro' => ($d['intro'] ?? '') === '' ? null : $d['intro'], 'questions' => $questions,
                'identity_mode' => $d['identity_mode'], 'opens_at' => $opens, 'closes_at' => $closes, 'max_responses' => $d['max_responses'] ?? null, 'anon_ip_hour' => $d['anon_ip_hour'] ?? SurveyService::ANON_PER_IP_HOUR, 'certificate' => $cert,
            ], 'survey.saved', ['questions' => count($questions)]);
        } catch (QueryException $e) {
            if (SurveyService::isUniqueViolation($e)) abort(409, 'มีแบบสอบถามที่ใช้ที่อยู่ลิงก์นี้แล้ว');
            throw $e;
        }
        return response()->json($this->row($row));
    }

    public function status(Request $r, int $id)
    {
        $survey = $this->svc->find($id);
        $d = $r->validate(['version' => 'required|integer|min:1', 'action' => 'required|in:open,close,draft']);
        $responses = DB::table('srv_responses')->where('survey_id', $id)->count();
        if ($d['action'] === 'open') {
            if (!$this->svc->questions($survey)) throw ValidationException::withMessages(['action' => 'ต้องมีคำถามอย่างน้อยหนึ่งข้อก่อนเปิดรับคำตอบ']);
            if ($survey->identity_mode === 'roster_code' && !DB::table('srv_roster')->where('survey_id', $id)->exists()) {
                throw ValidationException::withMessages(['action' => 'โหมดรหัสรายชื่อต้องนำเข้ารายชื่ออย่างน้อยหนึ่งรายการก่อนเปิด']);
            }
        }
        if ($d['action'] === 'draft' && $responses > 0) throw ValidationException::withMessages(['action' => 'มีผู้ตอบแล้ว กลับเป็นฉบับร่างไม่ได้ ให้ปิดรับคำตอบแทน']);
        $to = ['open' => 'open', 'close' => 'closed', 'draft' => 'draft'][$d['action']];
        // The network hash is only needed for the hourly anonymous budget: once the survey closes it is wiped (column is NOT NULL, so '').
        if ($to === 'closed') DB::table('srv_responses')->where('survey_id', $id)->where('ip_hash', '!=', '')->update(['ip_hash' => '']);
        return response()->json($this->row(Mutation::update('srv_surveys', $id, (int) $d['version'], ['status' => $to], 'survey.'.$d['action'], [])));
    }

    public function destroy(Request $r, int $id)
    {
        $this->svc->find($id);
        $d = $r->validate(['version' => 'required|integer|min:1']);
        DB::transaction(function () use ($id, $d) {
            // The survey row is locked first (finalize() takes the same lock), so no response can slip in between the check and the delete.
            DB::table('srv_surveys')->where('school_id', $this->svc->school())->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            if (DB::table('srv_responses')->where('survey_id', $id)->exists()) throw ValidationException::withMessages(['id' => 'มีผู้ตอบแล้ว ลบไม่ได้ ให้ปิดรับคำตอบแทน']);
            DB::table('srv_roster')->where('survey_id', $id)->delete();
            DB::table('srv_pending')->where('survey_id', $id)->delete();
            Mutation::delete('srv_surveys', $id, (int) $d['version'], 'survey.deleted');
        });
        return response()->json(['ok' => true]);
    }

    // ---- erasure (PDPA) ----------------------------------------------------------------------

    /** Delete one response (id from the CSV export with ?with_ids=1) together with its pending row and roster entry (the person's name). */
    public function destroyResponse(int $id, int $rid)
    {
        $this->svc->find($id);
        DB::transaction(function () use ($id, $rid) {
            DB::table('srv_surveys')->where('school_id', $this->svc->school())->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $resp = DB::table('srv_responses')->where('survey_id', $id)->where('id', $rid)->first() ?? abort(404, 'ไม่พบคำตอบนี้');
            DB::table('srv_pending')->where('survey_id', $id)->where(fn ($w) => $w->where('response_id', $rid)->orWhere('respondent_key', $resp->respondent_key))->delete();
            DB::table('srv_roster')->where('survey_id', $id)->where('code_hash', $resp->respondent_key)->delete();
            DB::table('srv_responses')->where('survey_id', $id)->where('id', $rid)->delete();
            Mutation::audit('survey.response_erased', 'srv_surveys', $id, ['response_id' => $rid, 'had_certificate' => $resp->certificate_no !== null]);
        });
        return response()->json(['ok' => true]);
    }

    /** Delete every response, pending row and roster entry of a CLOSED survey. Typed confirmation: the survey title. */
    public function purgeResponses(Request $r, int $id)
    {
        $survey = $this->svc->find($id);
        $d = $r->validate(['confirm' => 'required|string|max:300']);
        abort_unless($survey->status === 'closed', 422, 'ต้องปิดรับคำตอบก่อนจึงลบคำตอบทั้งหมดได้');
        abort_unless(trim($d['confirm']) === 'ลบคำตอบ '.$survey->title, 422, 'พิมพ์ข้อความยืนยันให้ตรงก่อนลบ');
        $n = 0;
        DB::transaction(function () use ($id, &$n) {
            DB::table('srv_surveys')->where('school_id', $this->svc->school())->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            DB::table('srv_pending')->where('survey_id', $id)->delete();
            DB::table('srv_roster')->where('survey_id', $id)->delete();
            $n = DB::table('srv_responses')->where('survey_id', $id)->delete();
            Mutation::audit('survey.responses_purged', 'srv_surveys', $id, ['responses' => $n]);
        });
        return response()->json(['ok' => true, 'deleted' => $n]);
    }

    // ---- roster ------------------------------------------------------------------------------

    public function roster(int $id)
    {
        $this->svc->find($id);
        $q = DB::table('srv_roster')->where('survey_id', $id);
        return response()->json(['total' => (clone $q)->count(), 'used' => (clone $q)->whereNotNull('used_at')->count(),
            'code_locked' => SurveyService::codeLocked($id), 'code_locked_notice' => SurveyService::codeLocked($id) ? 'ระบบหยุดรับรหัสของแบบสอบถามนี้ชั่วคราว เพราะมีการกรอกรหัสผิดจำนวนมาก อาจมีผู้พยายามเดารหัส' : null]);
    }

    /** Paste format: one person per line, "code,name" (comma, semicolon or tab). Codes are stored only as hashes. */
    public function importRoster(Request $r, int $id)
    {
        $survey = $this->svc->find($id);
        $d = $r->validate(['text' => 'required|string|max:2000000']);
        if ($survey->identity_mode !== 'roster_code') throw ValidationException::withMessages(['text' => 'แบบสอบถามนี้ไม่ได้ใช้โหมดรหัสรายชื่อ']);
        $lines = preg_split('/\R/u', $d['text']) ?: [];
        if (count($lines) > 20000) throw ValidationException::withMessages(['text' => 'นำเข้าได้ครั้งละไม่เกิน 20,000 บรรทัด']);
        $rows = [];
        $seen = [];
        $bad = [];
        $duplicates = 0;
        $weak = []; // all-digit or shorter than 8: typically sequential student numbers an outsider can walk through; refused (422)
        foreach ($lines as $n => $line) {
            if (trim($line) === '') continue;
            $parts = preg_split('/[\t,;]/u', $line, 2);
            $code = SurveyService::normaliseCode($parts[0] ?? '');
            $name = SurveyService::normaliseName($parts[1] ?? '');
            if (!preg_match('/^[A-Z0-9._-]{3,40}$/D', $code) || $name === '' || mb_strlen($name) > 160) { if (count($bad) < 20) $bad[] = $n + 1; continue; }
            $hash = $this->svc->key($id, 'roster_code', $code);
            if (isset($seen[$hash])) { $duplicates++; continue; }
            $seen[$hash] = true;
            if (ctype_digit($code) || strlen($code) < 8) { if (count($weak) < 50) $weak[] = $n + 1; $weakCount = ($weakCount ?? 0) + 1; continue; }
            $rows[] =['survey_id' => $id, 'code_hash' => $hash, 'display_name' => $name, 'used_at' => null];
        }
        if ($weak) {
            // One correct guess answers on behalf of a student and opens a certificate with the full name, so guessable codes are refused outright; nothing is imported.
            return response()->json(['message' => 'พบรหัส '.$weakCount.' รายการที่เป็นตัวเลขล้วนหรือสั้นกว่า 8 ตัวอักษร (เช่น เลขประจำตัวที่เรียงกัน) บุคคลภายนอกอาจเดาได้ จึงไม่นำเข้าเลย กรุณาใช้รหัสสุ่มยาว 8 ตัวอักษรขึ้นไปที่ไม่ใช่ตัวเลขล้วน', 'weak_codes' => $weakCount, 'weak_lines' => $weak, 'errors' => ['text' => ['รหัสเดาง่าย (ตัวเลขล้วนหรือสั้นกว่า 8 ตัวอักษร) บรรทัดที่ '.implode(', ', $weak)]]], 422);
        }
        $before = DB::table('srv_roster')->where('survey_id', $id)->count();
        DB::transaction(function () use ($rows) { foreach (array_chunk($rows, 400) as $chunk) DB::table('srv_roster')->insertOrIgnore($chunk); });
        $added = DB::table('srv_roster')->where('survey_id', $id)->count() - $before;
        $skipped = count($rows) - $added + $duplicates;
        Mutation::audit('survey.roster_import', 'srv_surveys', $id, ['added' => $added, 'skipped' => $skipped, 'invalid' => count($bad)]);
        return response()->json(['added' => $added, 'skipped_existing_or_duplicate' => $skipped, 'invalid_lines' => $bad, 'total' => DB::table('srv_roster')->where('survey_id', $id)->count()]);
    }

    // ---- results and export --------------------------------------------------------------------

    public function results(int $id)
    {
        $survey = $this->svc->find($id);
        $questions = $this->svc->questions($survey);
        $stats = [];
        foreach ($questions as $q) {
            $stats[$q['key']] = ['key' => $q['key'], 'type' => $q['type'], 'label' => $q['label'], 'answered' => 0, 'counts' => [], 'sum' => 0, 'texts' => []];
            if (isset($q['options'])) foreach ($q['options'] as $o) $stats[$q['key']]['counts'][$o] = 0;
            if ($q['type'] === 'scale') for ($i = 1; $i <= (int) ($q['max'] ?? 5); $i++) $stats[$q['key']]['counts'][(string) $i] = 0;
        }
        $total = 0; $certs = 0; $scoreSum = 0; $scored = 0; $first = null; $last = null;
        DB::table('srv_responses')->where('survey_id', $id)->orderBy('id')->select('id', 'answers', 'score', 'certificate_no', 'created_at')->chunkById(1000, function ($chunk) use (&$stats, &$total, &$certs, &$scoreSum, &$scored, &$first, &$last) {
            foreach ($chunk as $resp) {
                $total++; $certs += $resp->certificate_no ? 1 : 0;
                if ($resp->score !== null) { $scoreSum += (int) $resp->score; $scored++; }
                $first ??= $resp->created_at; $last = $resp->created_at;
                foreach (json_decode((string) $resp->answers, true) ?: [] as $key => $value) {
                    if (!isset($stats[$key])) continue;
                    $st = &$stats[$key]; $st['answered']++;
                    if ($st['type'] === 'text') { if (count($st['texts']) < 30) $st['texts'][] = (string) $value; }
                    elseif ($st['type'] === 'multi') foreach ((array) $value as $v) { $st['counts'][$v] = ($st['counts'][$v] ?? 0) + 1; }
                    else { $st['counts'][(string) $value] = ($st['counts'][(string) $value] ?? 0) + 1; if ($st['type'] === 'scale') $st['sum'] += (int) $value; }
                    unset($st);
                }
            }
        }, 'id');
        $out = array_map(function ($st) {
            $st['average'] = $st['type'] === 'scale' && $st['answered'] ? round($st['sum'] / $st['answered'], 2) : null;
            unset($st['sum']);
            $st['counts'] = array_map(fn ($label, $n) => ['label' => (string) $label, 'count' => $n], array_keys($st['counts']), array_values($st['counts']));
            if ($st['type'] === 'text') unset($st['counts']);
            return $st;
        }, array_values($stats));
        return response()->json([
            'total' => $total, 'certificates' => $certs, 'average_score' => $scored ? round($scoreSum / $scored, 1) : null,
            'first_at' => SurveyService::isoTime($first), 'last_at' => SurveyService::isoTime($last), 'questions' => $out,
        ]);
    }

    /** Spreadsheet formula guard: cells that start with = + - @ tab or CR are prefixed with an apostrophe. */
    public static function cell(mixed $v): string
    {
        $s = (string) $v;
        return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'".$s : $s;
    }

    public function export(Request $req, int $id)
    {
        $withIds = $req->boolean('with_ids'); // appends the response id (last column) so one response can be erased on request
        $survey = $this->svc->find($id);
        $questions = $this->svc->questions($survey);
        $withName = $survey->identity_mode !== 'anonymous';
        $cert = $this->svc->certificate($survey);
        $scored = collect($questions)->contains(fn ($q) => SurveyService::hasCorrect($q));
        $head = ['ลำดับ', 'เวลาตอบ (เวลาไทย)'];
        if ($withName) $head[] = 'ชื่อ-สกุล';
        if ($cert) $head[] = 'เลขที่เกียรติบัตร';
        if ($scored) $head[] = 'คะแนน (ร้อยละ)';
        foreach ($questions as $q) $head[] = $q['label'];
        if ($withIds) $head[] = 'รหัสคำตอบ';
        \App\Support\Saan\Mutation::audit('survey.exported', 'srv_surveys', $id, ['identity_mode' => $survey->identity_mode]);
        $name = 'survey-'.$survey->slug.'-'.now('Asia/Bangkok')->format('Ymd').'.csv';
        return response()->streamDownload(function () use ($id, $questions, $head, $withName, $cert, $scored, $withIds) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map([self::class, 'cell'], $head), ',', '"', '');
            $n = 0;
            DB::table('srv_responses')->where('survey_id', $id)->orderBy('id')->chunkById(1000, function ($chunk) use ($out, $questions, $withName, $cert, $scored, $withIds, &$n) {
                foreach ($chunk as $resp) {
                    $answers = json_decode((string) $resp->answers, true) ?: [];
                    $row = [++$n, SurveyService::isoTime($resp->created_at) ? str_replace('T', ' ', SurveyService::isoTime($resp->created_at)) : ''];
                    if ($withName) $row[] = $resp->display_name;
                    if ($cert) $row[] = $resp->certificate_no;
                    if ($scored) $row[] = $resp->score;
                    foreach ($questions as $q) { $v = $answers[$q['key']] ?? ''; $row[] = is_array($v) ? implode(' | ', $v) : $v; }
                    if ($withIds) $row[] = $resp->id;
                    fputcsv($out, array_map([self::class, 'cell'], $row), ',', '"', '');
                }
            }, 'id');
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
