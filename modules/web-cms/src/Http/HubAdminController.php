<?php

namespace Saan\Modules\WebCms\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Saan\Modules\WebCms\HubAdmin;
use Saan\Modules\WebCms\HubFiles;
use Saan\Modules\WebCms\Hubs;

/** F4 internal screens and APIs. Staff roles may read; writes need Hubs::canManage (admin or the head of that group), hub create/delete need admin. */
class HubAdminController
{
    private function hub(int $id): object
    {
        return Hubs::find($id) ?? abort(404, 'ไม่พบหน้ากลุ่ม');
    }

    private function manage(int $id): object
    {
        $h = $this->hub($id);
        abort_unless(Hubs::canManage(auth()->user(), $h), 403, 'เฉพาะผู้ดูแลระบบและหัวหน้ากลุ่มของหน้านี้เท่านั้นที่แก้ไขได้');
        return $h;
    }

    private function adminOnly(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403, 'เฉพาะผู้ดูแลระบบเท่านั้น');
    }

    private function hubRules(bool $create): array
    {
        $sometimes = $create ? 'required' : 'sometimes';
        return [
            'slug' => [$sometimes, 'string', 'min:2', 'max:80', 'regex:'.Hubs::SLUG],
            'title' => [$sometimes, 'string', 'max:160'],
            'kind' => ['sometimes', Rule::in(array_keys(Hubs::KINDS))],
            'group_name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:4000'],
            'news_category' => ['nullable', 'string', 'max:30', Rule::exists('cms_categories', 'slug')->where('school_id', Hubs::school())],
            'news_tag' => ['nullable', 'string', 'max:40'],
            'news_limit' => ['sometimes', 'integer', 'between:1,20'],
            'box_limit' => ['sometimes', 'integer', 'between:1,20'],
            'sort' => ['sometimes', 'integer', 'between:-9999,9999'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'contact' => ['nullable', 'string', 'max:1000'],
            'committee' => ['nullable', 'array', 'max:'.Hubs::COMMITTEE_MAX],
            'committee.*' => ['array:name,role'],
            'committee.*.name' => ['required', 'string', 'max:120'],
            'committee.*.role' => ['nullable', 'string', 'max:120'],
        ];
    }

    private function trimmed(array $d): array
    {
        foreach (['group_name', 'news_tag', 'news_category', 'description', 'internal_note', 'contact'] as $k) if (array_key_exists($k, $d)) { $d[$k] = trim((string) $d[$k]); if ($d[$k] === '') $d[$k] = null; }
        if (array_key_exists('committee', $d)) { // stored as JSON text: [{name, role}] in the order typed
            $rows = array_values(array_map(fn ($r) => ['name' => trim((string) $r['name']), 'role' => trim((string) ($r['role'] ?? ''))], (array) $d['committee']));
            $d['committee'] = $rows ? json_encode($rows, JSON_UNESCAPED_UNICODE) : null;
        }
        return $d;
    }

    // ---------------------------------------------------------------- hubs

    public function index()
    {
        return response()->json(HubAdmin::overview(auth()->user()));
    }

    public function show(Request $r, int $id)
    {
        $f = $r->validate(['category_id' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:100', 'status' => 'nullable|in:published,draft', 'page' => 'nullable|integer|min:1']);
        return response()->json(HubAdmin::detail($this->hub($id), auth()->user(), $f));
    }

    public function store(Request $r)
    {
        $this->adminOnly();
        $d = $this->trimmed($r->validate($this->hubRules(true)));
        $hub = HubAdmin::createHub($d);
        return response()->json(HubAdmin::detail($hub, auth()->user(), []), 201);
    }

    /** Admin: a new page from a ready template (association pages, monthly trial-balance reports). The page starts as a draft unless the admin publishes it. */
    public function storeFromTemplate(Request $r)
    {
        $this->adminOnly();
        $d = $this->trimmed($r->validate(['template' => ['required', Rule::in(array_keys(Hubs::TEMPLATES))]] + array_intersect_key($this->hubRules(false), array_flip(['slug', 'title', 'description', 'contact', 'committee', 'committee.*', 'committee.*.name', 'committee.*.role', 'status']))));
        $hub = HubAdmin::createFromTemplate($d);
        return response()->json(HubAdmin::detail($hub, auth()->user(), []), 201);
    }

    public function update(Request $r, int $id)
    {
        $hub = $this->manage($id);
        $d = $this->trimmed($r->validate($this->hubRules(false) + ['version' => 'required|integer|min:1']));
        $admin = auth()->user()->role === 'admin';
        abort_if(!$admin && array_intersect(array_keys($d), HubAdmin::ADMIN_FIELDS), 403, 'เฉพาะผู้ดูแลระบบเท่านั้นที่เปลี่ยนที่อยู่หน้า ประเภท และกลุ่มที่ผูกไว้');
        $row = HubAdmin::updateHub($hub, (int) $d['version'], $d, $admin);
        return response()->json(HubAdmin::detail($row, auth()->user(), []));
    }

    public function destroy(Request $r, int $id)
    {
        $this->adminOnly();
        $hub = $this->hub($id);
        $d = $r->validate(['version' => 'required|integer|min:1']);
        HubAdmin::deleteHub($hub, (int) $d['version']);
        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------------------------- categories

    public function addCategory(Request $r, int $id)
    {
        $hub = $this->manage($id);
        $d = $r->validate(['label' => 'required|string|max:120', 'parent_id' => 'nullable|integer|min:1', 'rule' => ['nullable', Rule::in(array_keys(Hubs::RULES))]]);
        return response()->json(HubAdmin::presentCategory(HubAdmin::addCategory($hub, trim($d['label']), isset($d['parent_id']) ? (int) $d['parent_id'] : null, $d['rule'] ?? null)), 201);
    }

    public function updateCategory(Request $r, int $id, int $cid)
    {
        $hub = $this->manage($id);
        $d = $r->validate(['version' => 'required|integer|min:1', 'label' => 'sometimes|string|max:120', 'is_hidden' => 'sometimes|boolean', 'rule' => ['sometimes', 'nullable', Rule::in(array_keys(Hubs::RULES))]]);
        if (isset($d['label'])) $d['label'] = trim($d['label']);
        abort_if(isset($d['label']) && $d['label'] === '', 422, 'กรุณาใส่ชื่อหมวด');
        return response()->json(HubAdmin::presentCategory(HubAdmin::updateCategory($hub, $cid, (int) $d['version'], $d)));
    }

    public function deleteCategory(Request $r, int $id, int $cid)
    {
        $hub = $this->manage($id);
        $d = $r->validate(['version' => 'required|integer|min:1']);
        HubAdmin::deleteCategory($hub, $cid, (int) $d['version']);
        return response()->json(['ok' => true]);
    }

    public function orderCategories(Request $r, int $id)
    {
        $hub = $this->manage($id);
        $d = $r->validate(['items' => 'required|array|min:1|max:100', 'items.*.id' => 'required|integer|min:1|distinct', 'items.*.version' => 'required|integer|min:1']);
        HubAdmin::orderCategories($hub, $d['items']);
        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------------------------- documents

    public function addDocuments(Request $r, int $id)
    {
        $hub = $this->manage($id);
        $d = $r->validate(['category_id' => 'required|integer|min:1', 'title' => 'nullable|string|max:200', 'doc_date' => 'nullable|date_format:Y-m-d|after:1999-12-31|before:2101-01-01',
            'external_url' => 'nullable|string|max:500', 'is_published' => 'sometimes|boolean', 'note' => 'nullable|string|max:1000', 'files' => 'sometimes|array|max:'.HubFiles::MAX_FILES, 'files.*' => 'file',
            'period_month' => 'nullable|integer|between:1,12', 'period_year' => 'nullable|integer|between:'.implode(',', \App\Support\Fiscal::reportYears()), 'replace' => 'sometimes|boolean']);
        $made = HubAdmin::addDocuments($hub, $d, array_values((array) $r->file('files', [])));
        return response()->json(['data' => array_map(fn ($m) => HubAdmin::presentDocument($m, true), HubAdmin::rows(array_map(fn ($m) => (int) $m->id, $made)))], 201);
    }

    public function updateDocument(Request $r, int $id, int $did)
    {
        $hub = $this->manage($id);
        $d = $r->validate(['version' => 'required|integer|min:1', 'title' => 'sometimes|string|max:200', 'doc_date' => 'sometimes|date_format:Y-m-d|after:1999-12-31|before:2101-01-01',
            'category_id' => 'sometimes|integer|min:1', 'is_published' => 'sometimes|boolean', 'pinned' => 'sometimes|boolean', 'note' => 'nullable|string|max:1000',
            'external_url' => 'nullable|string|max:500', 'remove_file' => 'sometimes|boolean', 'file' => 'sometimes|file',
            'period_month' => 'nullable|integer|between:1,12', 'period_year' => 'nullable|integer|between:'.implode(',', \App\Support\Fiscal::reportYears()), 'replace' => 'sometimes|boolean']);
        abort_if(isset($d['title']) && trim($d['title']) === '', 422, 'กรุณาใส่ชื่อเอกสาร');
        if (isset($d['title'])) $d['title'] = trim($d['title']);
        $row = HubAdmin::updateDocument($hub, $did, (int) $d['version'], $d, $r->file('file'));
        return response()->json(HubAdmin::presentDocument(HubAdmin::rows([(int) $row->id])[0], true));
    }

    public function deleteDocument(Request $r, int $id, int $did)
    {
        $hub = $this->manage($id);
        $d = $r->validate(['version' => 'required|integer|min:1']);
        HubAdmin::deleteDocument($hub, $did, (int) $d['version']);
        return response()->json(['ok' => true]);
    }

    public function orderDocuments(Request $r, int $id)
    {
        $hub = $this->manage($id);
        $d = $r->validate(['category_id' => 'required|integer|min:1', 'items' => 'required|array|min:1|max:100', 'items.*.id' => 'required|integer|min:1|distinct', 'items.*.version' => 'required|integer|min:1']);
        HubAdmin::orderDocuments($hub, (int) $d['category_id'], $d['items']);
        return response()->json(['ok' => true]);
    }

    public function publishDocuments(Request $r, int $id)
    {
        $hub = $this->manage($id);
        $d = $r->validate(['published' => 'required|boolean', 'items' => 'required|array|min:1|max:100', 'items.*.id' => 'required|integer|min:1|distinct', 'items.*.version' => 'required|integer|min:1']);
        return response()->json(['changed' => HubAdmin::setPublished($hub, $d['items'], (bool) $d['published'])]);
    }

    /** Authenticated look at a document's file (also drafts). Does not count. Staff only. */
    public function file(int $did)
    {
        $doc = DB::table('cms_hub_documents')->where('school_id', Hubs::school())->where('id', $did)->first() ?? abort(404);
        // a draft document (or any document of an unpublished hub) is for the managers of that hub only; other staff get the same 404 as a missing id
        $hub = Hubs::find((int) $doc->hub_id) ?? abort(404);
        abort_unless(Hubs::canManage(auth()->user(), $hub) || ($doc->is_published && $hub->status === 'published'), 404);
        abort_if($doc->media_id === null, 404);
        $m = DB::table('cms_media')->where('id', $doc->media_id)->where('school_id', Hubs::school())->first() ?? abort(404);
        $disk = Storage::disk('local');
        abort_unless(HubFiles::allowedMime((string) $m->mime) && $disk->exists($m->path), 404);
        return response()->download($disk->path($m->path), $doc->file_name ?: basename($m->path), ['Content-Type' => $m->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "sandbox; default-src 'none'"], 'attachment');
    }

    /** Preview of the hub page as the public will see it, with drafts marked (staff only). */
    public function preview(int $id)
    {
        $hub = $this->manage($id); // shows drafts, so managers only
        return view('web-cms::hub.show', app(HubPublicController::class)->data($hub, true));
    }
}
