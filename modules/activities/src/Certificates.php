<?php

namespace Saan\Modules\Activities;

use App\Support\Saan\DbError;
use App\Support\Saan\Mutation;
use App\Support\Saan\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Activity certificates for club members whose term result is ผ.
 * The surveys module's numbering lives in a service that reads srv_* tables and is only present when that module is active, so this module
 * keeps its own counter (act_counters) with the same format: <prefix>-<Buddhist year>-<6 digits>, bumped under a row lock inside the issuing transaction.
 */
class Certificates
{
    public function __construct(private Clubs $clubs)
    {
    }

    public static function buddhistYear(): int
    {
        return (int) now('Asia/Bangkok')->year + 543;
    }

    /** The prefix the surveys module uses (school.short_code, else the school id). Activity numbers must never share it: both modules count separately. */
    public static function surveysCode(): string
    {
        $short = '';
        try { $short = (string) Settings::get('school.short_code', ''); } catch (\Throwable) {}
        $short = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $short) ?? '');
        return $short !== '' ? substr($short, 0, 12) : (string) Common::school();
    }

    public function prefix(): string
    {
        $p = '';
        try { $p = (string) Settings::get('activities.cert_prefix', ''); } catch (\Throwable) {}
        $p = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $p) ?? ''), 0, 12);
        if ($p !== '' && $p !== self::surveysCode()) return $p;
        return substr(self::surveysCode(), 0, 9).'ACT'; // default, distinct from the surveys numbers
    }

    /** Must run inside the caller's transaction: the counter row stays locked until commit, so numbers never repeat and a rollback leaves no gap. */
    public function nextNumber(): string
    {
        $school = Common::school();
        $year = self::buddhistYear();
        DB::table('act_counters')->insertOrIgnore(['school_id' => $school, 'year' => $year, 'last' => 0]);
        DB::table('act_counters')->where('school_id', $school)->where('year', $year)->update(['last' => DB::raw('last + 1')]);
        $seq = (int) DB::table('act_counters')->where('school_id', $school)->where('year', $year)->value('last');
        return sprintf('%s-%d-%06d', $this->prefix(), $year, $seq);
    }

    /** Issues certificates for every enrolled member with result ผ that has none yet. */
    public function issue(int $clubId): array
    {
        $club = $this->clubs->find($clubId);
        $this->clubs->requireManage($club);
        $this->clubs->requireTermOpen($club);
        return DB::transaction(function () use ($club) {
            DB::table('act_clubs')->where('id', $club->id)->lockForUpdate()->first(); // one issuing run per club at a time
            $s = $this->clubs->settings();
            $term = Common::termLabel((int) $club->term_id);
            $school = Common::schoolName();
            $date = now('Asia/Bangkok')->locale('th')->translatedFormat('j F').' '.(now('Asia/Bangkok')->year + 543);
            $issued = [];
            $members = DB::table('act_members as m')->join('students as st', 'st.id', '=', 'm.student_id')->where('m.school_id', Common::school())->where('m.club_id', $club->id)
                ->where('m.status', 'enrolled')->where('m.result', 'ผ')->whereNotExists(fn ($q) => $q->from('act_certificates as c')->whereColumn('c.member_id', 'm.id'))
                ->orderBy('st.code')->select('m.id', 'm.student_id', 'st.name')->get();
            foreach ($members as $m) {
                $no = $this->nextNumber();
                $snapshot = ['student_name' => $m->name, 'club_name' => $club->name, 'term_label' => $term, 'school_name' => $school, 'date' => $date, 'number' => $no,
                    'signer_name' => $s['signer_name'], 'signer_title' => $s['signer_title']];
                try {
                    $row = Mutation::insert('act_certificates', ['club_id' => (int) $club->id, 'member_id' => (int) $m->id, 'student_id' => (int) $m->student_id, 'number' => $no,
                        'snapshot' => $snapshot, 'issued_at' => now()], 'activities.certificate.issue', ['number' => $no, 'club_id' => (int) $club->id]);
                } catch (QueryException $e) {
                    if (DbError::isUnique($e)) abort(409, 'ออกเลขเกียรติบัตรไม่สำเร็จ กรุณาลองใหม่');
                    throw $e;
                }
                $issued[] = ['id' => (int) $row->id, 'number' => $no, 'student_name' => $m->name];
            }
            return ['issued' => $issued, 'count' => count($issued)];
        });
    }

    public function forClub(int $clubId, ?int $only = null): array
    {
        $club = $this->clubs->find($clubId);
        $this->clubs->requireManage($club);
        return DB::table('act_certificates')->where('school_id', Common::school())->where('club_id', $clubId)->when($only, fn ($q) => $q->where('id', $only))->orderBy('number')->get()
            ->map(fn ($c) => ['id' => (int) $c->id, 'number' => $c->number, 'student_id' => (int) $c->student_id, 'snapshot' => json_decode((string) $c->snapshot, true) ?: []])->all();
    }
}
