<?php

namespace Saan\Modules\WebIta\Http;

use App\Http\Controllers\SchoolWebsiteController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Saan\Modules\WebIta\ItaCheck;
use Saan\Modules\WebIta\ItaSources;
use Saan\Modules\WebIta\ItaRepository;

class ItaController
{
    public function __construct(private ItaRepository $ita) {}

    public function years() { return response()->json(['data' => $this->ita->years()]); }

    public function read(int $id) { $this->ita->authorize(); return response()->json($this->ita->read($id)); }

    public function create(Request $r)
    {
        $this->ita->authorize();
        $d = $r->validate(['fiscal_year' => 'required|integer|between:2560,2700', 'copy_from' => 'nullable|integer|min:1', 'preset' => 'nullable|boolean']);
        return response()->json($this->ita->create((int) $d['fiscal_year'], $d['copy_from'] ?? null, !empty($d['preset']) && empty($d['copy_from'])), 201);
    }

    public function save(Request $r, int $id)
    {
        $this->ita->authorize();
        $d = $r->validate([
            'version' => 'required|integer|min:1', 'title' => 'required|string|max:200',
            'topics' => 'present|array|max:80', 'topics.*' => 'array:code,title,items',
            'topics.*.code' => 'nullable|string|max:20', 'topics.*.title' => 'required|string|max:300',
            'topics.*.items' => 'present|array|max:60', 'topics.*.items.*' => 'array:title,url,media_id,note,source,source_opts',
            'topics.*.items.*.title' => 'required|string|max:300', 'topics.*.items.*.url' => 'nullable|string|max:1000',
            'topics.*.items.*.media_id' => 'nullable|integer|min:1', 'topics.*.items.*.note' => 'nullable|string|max:500',
            'topics.*.items.*.source' => ['nullable', 'string', Rule::in(array_keys(ItaSources::KEYS))], 'topics.*.items.*.source_opts' => 'nullable|array:category,slug,stage', 'topics.*.items.*.source_opts.stage' => ['nullable', 'string', Rule::in(array_keys(ItaSources::STAGES))], 'topics.*.items.*.source_opts.*' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/D'],
        ]);
        return response()->json($this->ita->save($id, (int) $d['version'], $d['title'], $d['topics']));
    }

    public function status(Request $r, int $id)
    {
        $this->ita->authorize();
        $d = $r->validate(['version' => 'required|integer|min:1', 'action' => 'required|in:publish,unpublish']);
        return response()->json($this->ita->setStatus($id, (int) $d['version'], $d['action'] === 'publish'));
    }

    /** GET /api/ita/check?year=2570: completeness of the O1..O18 items of one fiscal year (draft included: this is the staff view). */
    public function check(Request $r)
    {
        $this->ita->authorize();
        $d = $r->validate(['year' => 'nullable|integer|between:2560,2700']);
        return response()->json(ItaCheck::run((int) ($d['year'] ?? \App\Support\Fiscal::current())));
    }

    public function public(?int $fiscalYear = null)
    {
        $school = (int) config('school.school_id');
        $years = DB::table('ita_years')->where('school_id', $school)->where('status', 'published')->orderByDesc('fiscal_year')->get(['id', 'fiscal_year', 'title']);
        $year = $fiscalYear ? $years->firstWhere('fiscal_year', $fiscalYear) : $years->first();
        abort_if($fiscalYear && !$year, 404);
        $data = $year ? $this->ita->read($year->id, true) : null;
        // O13/O14 link themselves to the complaints module (form, status page, latest published annual report) when it is active; no school address is written anywhere
        // A topic whose items already use an automatic source (F6) gets its links from those items instead, so nothing is listed twice.
        if ($data && class_exists(\Saan\Modules\Complaints\ItaLinks::class)) $data['topics'] = array_map(fn ($t) => $t + ['auto_links' => array_filter($t['items'], fn ($i) => !empty($i['source'])) ? [] : \Saan\Modules\Complaints\ItaLinks::forTopic($t, (int) $year->fiscal_year)], $data['topics']);
        return view('web-ita::public', app(SchoolWebsiteController::class)->context())->with(['ita' => $data, 'years' => $years, 'pageTitle' => $data['year']['title'] ?? 'การเปิดเผยข้อมูลสาธารณะ (ITA)']);
    }
}
