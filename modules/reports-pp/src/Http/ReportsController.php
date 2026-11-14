<?php

namespace Saan\Modules\ReportsPp\Http;

use Illuminate\Http\Request;
use Saan\Modules\ReportsPp\Calc;
use Saan\Modules\ReportsPp\ReportsPp;

class ReportsController
{
    public function __construct(private ReportsPp $r) {}

    private function term(Request $req): object
    {
        $d = $req->validate(['term_id' => 'nullable|integer|min:1']);
        return $this->r->term(isset($d['term_id']) ? (int) $d['term_id'] : null);
    }

    // ---- JSON ----------------------------------------------------------------------------------------------------

    public function overview(Request $req)
    {
        $term = $this->term($req);
        $out = ['term' => ['id' => (int) $term->id, 'year' => (int) $term->year, 'term' => (int) $term->term], 'terms' => $this->r->terms(),
            'courses' => $this->r->listCourses($term), 'classrooms' => $this->r->listClassrooms($term), 'settings' => $this->r->settings(),
            'manager' => $this->r->isManager(), 'special_codes' => Calc::SPECIAL];
        if ($this->r->isManager()) $out['templates'] = $this->r->templates();
        return response()->json($out);
    }

    public function specialList(int $id) { return response()->json($this->r->specialList($id)); }

    public function specialSave(Request $req, int $id)
    {
        $d = $req->validate(['student_id' => 'required|integer|min:1', 'code' => 'present|nullable|string|max:8', 'note' => 'nullable|string|max:200', 'version' => 'required|integer|min:0', 'reason' => 'nullable|string|max:200']);
        return response()->json($this->r->saveSpecial($id, (int) $d['student_id'], $d['code'], $d['note'] ?? null, (int) $d['version'], $d['reason'] ?? null));
    }

    public function remarkList(Request $req, int $classroomId) { return response()->json($this->r->remarkList($classroomId, $this->term($req))); }

    public function remarkSave(Request $req)
    {
        $d = $req->validate(['student_id' => 'required|integer|min:1', 'term_id' => 'required|integer|min:1', 'remark' => 'present|nullable|string|max:500', 'version' => 'required|integer|min:0']);
        return response()->json($this->r->saveRemark((int) $d['student_id'], (int) $d['term_id'], (string) ($d['remark'] ?? ''), (int) $d['version']));
    }

    public function settingsSave(Request $req)
    {
        $d = $req->validate(['version' => 'required|integer|min:0', 'show_rank' => 'nullable|boolean', 'gpa_rounding' => 'nullable|in:truncate,round', 'signers' => 'nullable|array|max:6', 'signers.*' => 'array:label,name', 'signers.*.label' => 'required|string|max:80', 'signers.*.name' => 'nullable|string|max:120']);
        $version = (int) $d['version'];
        unset($d['version']);
        return response()->json($this->r->saveSettings($d, $version));
    }

    public function templateSave(Request $req)
    {
        $d = $req->validate(['kind' => 'required|in:'.implode(',', ReportsPp::KINDS), 'year_be' => 'required|integer|between:2500,2700', 'version' => 'required|integer|min:0',
            'options' => 'required|array', 'options.title' => 'nullable|string|max:120', 'options.footer_note' => 'nullable|string|max:300', 'options.font_size' => 'nullable|integer|between:10,16',
            'options.show_components' => 'nullable|boolean', 'options.show_attributes' => 'nullable|boolean', 'options.show_attendance' => 'nullable|boolean']);
        return response()->json($this->r->saveTemplate($d['kind'], (int) $d['year_be'], $d['options'], (int) $d['version']));
    }

    // ---- print views ---------------------------------------------------------------------------------------------

    public function pp5(int $id) { return view('reports-pp::pp5', ['d' => $this->r->pp5($id)]); }

    public function pp6Student(Request $req, int $studentId) { return view('reports-pp::pp6', ['d' => $this->r->pp6Student($studentId, $this->term($req))]); }

    public function pp6Class(Request $req, int $classroomId) { return view('reports-pp::pp6', ['d' => $this->r->pp6Class($classroomId, $this->term($req))]); }

    public function summary(Request $req, int $classroomId) { return view('reports-pp::summary', ['d' => $this->r->summary($classroomId, $this->term($req))]); }

    // ---- CSV -----------------------------------------------------------------------------------------------------

    private function csv(string $name, array $rows)
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF"); // BOM so Excel reads Thai correctly
        foreach ($rows as $row) fputcsv($h, array_map([Calc::class, 'csvCell'], $row), ',', '"', '');
        rewind($h);
        $body = stream_get_contents($h);
        fclose($h);
        return response($body, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function pp5Csv(int $id)
    {
        $d = $this->r->pp5($id);
        $comps = $d['course']['policy']['components'];
        $head = ['เลขที่', 'รหัสนักเรียน', 'ชื่อ-สกุล'];
        foreach ($comps as $c) $head[] = $c['label'].' ('.$c['max'].')';
        array_push($head, 'รวม', 'ผลการเรียน', 'หมายเหตุ');
        $rows = [[$d['course']['code'].' '.$d['course']['name'], $d['classroom'], 'ปีการศึกษา '.$d['term']['year'].' ภาคเรียนที่ '.$d['term']['term']], $head];
        foreach ($d['rows'] as $r) {
            $x = [$r['student']['roll'] ?? '', $r['student']['code'], $r['student']['name']];
            foreach ($comps as $c) $x[] = Calc::fmtScore($r['result']['centi'][$c['key']] ?? null);
            $x[] = $r['result']['state'] === 'graded' ? Calc::fmtCenti($r['result']['total_centi']) : '';
            $x[] = $r['result']['grade'] ?? '';
            $x[] = $r['result']['note'] ?? '';
            $rows[] = $x;
        }
        return $this->csv('pp5-course-'.$id.'.csv', $rows);
    }

    public function pp6Csv(Request $req, int $classroomId)
    {
        $d = $this->r->pp6Class($classroomId, $this->term($req));
        $first = $d['pages'][0]['lines'] ?? [];
        $head = ['เลขที่', 'รหัสนักเรียน', 'ชื่อ-สกุล'];
        foreach ($first as $l) $head[] = $l['course']['code'].' '.$l['course']['name'].' (หน่วยกิต '.Calc::fmtCredit($l['course']['credit_tenths']).')';
        array_push($head, 'GPA', 'หน่วยกิตที่นำมาคำนวณ');
        if ($d['show_rank']) $head[] = 'อันดับ';
        $rows = [$head];
        foreach ($d['pages'] as $p) {
            $x = [$p['student']['roll'] ?? '', $p['student']['code'], $p['student']['name']];
            foreach ($p['lines'] as $l) $x[] = $l['result']['state'] === 'graded' ? $l['result']['grade'] : ($l['result']['special'] ?? '');
            $x[] = $p['gpa']['gpa_centi'] === null ? '' : Calc::fmtCenti($p['gpa']['gpa_centi']);
            $x[] = Calc::fmtCredit($p['gpa']['credits_tenths'] ?: null);
            if ($d['show_rank']) $x[] = $p['rank'] ?? '';
            $rows[] = $x;
        }
        return $this->csv('pp6-class-'.$classroomId.'.csv', $rows);
    }

    public function summaryCsv(Request $req, int $classroomId)
    {
        $d = $this->r->summary($classroomId, $this->term($req));
        $rows = [array_merge(['รหัสวิชา', 'ชื่อวิชา', 'จำนวนนักเรียน'], Calc::BUCKETS, ['ร', 'มผ', 'มส', 'ยังไม่สมบูรณ์', 'คะแนนเฉลี่ย'])];
        foreach ($d['subjects'] as $s) {
            $x = [$s['course']['code'], $s['course']['name'], $s['dist']['total']];
            foreach (Calc::BUCKETS as $b) $x[] = $s['dist'][$b];
            array_push($x, $s['dist']['ร'], $s['dist']['มผ'], $s['dist']['มส'], $s['dist']['incomplete'], $s['dist']['graded'] ? number_format($s['dist']['sum_total'] / $s['dist']['graded'], 2, '.', '') : '');
            $rows[] = $x;
        }
        $rows[] = [];
        $rows[] = ['รหัสนักเรียน', 'ชื่อ-สกุล', 'GPA', 'อันดับ', 'ได้ 0', 'ร', 'มผ', 'มส', 'ยังไม่สมบูรณ์'];
        foreach ($d['students'] as $s) {
            $rows[] = [$s['student']['code'], $s['student']['name'], $s['gpa']['gpa_centi'] === null ? '' : Calc::fmtCenti($s['gpa']['gpa_centi']), $s['rank'] ?? '', $s['zero'], $s['r'], $s['mp'], $s['ms'], $s['incomplete']];
        }
        return $this->csv('summary-class-'.$classroomId.'.csv', $rows);
    }
}
