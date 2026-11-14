<?php

namespace Saan\Modules\Documents\Http;

use App\Support\Saan\Idempotency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Saan\Modules\Documents\Documents;

class DocumentsController
{
    public function __construct(private Documents $docs) {}

    private function fields(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        return [
            'doc_no' => 'nullable|string|max:80', 'doc_date' => $req.'|date_format:Y-m-d', 'received_on' => 'nullable|date_format:Y-m-d',
            'from_org' => 'nullable|string|max:200', 'to_org' => 'nullable|string|max:200', 'subject' => $req.'|string|min:1|max:300',
            'urgency' => ['sometimes', Rule::in(array_keys(Documents::URGENCY))], 'secrecy' => ['sometimes', Rule::in(array_keys(Documents::SECRECY))],
            'summary' => 'nullable|string|max:5000',
        ];
    }

    public function meta() { return response()->json($this->docs->meta()); }
    public function books() { return response()->json(['data' => $this->docs->books()]); }
    public function recipients() { return response()->json(['data' => $this->docs->recipients()]); }

    public function updateBook(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'label' => 'sometimes|string|min:1|max:120', 'prefix' => 'sometimes|nullable|string|max:40']);
        $version = (int) $d['version']; unset($d['version']);
        return response()->json($this->docs->updateBook($id, $version, $d));
    }

    public function index(Request $r)
    {
        $p = $r->validate(['q' => 'nullable|string|max:100', 'year_be' => 'nullable|integer|min:2400|max:2700', 'kind' => ['nullable', Rule::in(array_keys(Documents::KINDS))],
            'status' => ['nullable', Rule::in(array_keys(Documents::STATUS))], 'urgency' => ['nullable', Rule::in(array_keys(Documents::URGENCY))],
            'page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($this->docs->list($p));
    }

    public function store(Request $r)
    {
        $d = $r->validate(['kind' => ['required', Rule::in(array_keys(Documents::KINDS))], 'registered_on' => 'nullable|date_format:Y-m-d'] + $this->fields(true));
        return Idempotency::run($r, fn () => response()->json($this->docs->register($d), 201));
    }

    public function show(int $id) { return response()->json($this->docs->show($id)); }

    public function update(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1'] + $this->fields(false));
        $version = (int) $d['version']; unset($d['version']);
        return response()->json($this->docs->update($id, $version, $d));
    }

    public function cancel(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:2|max:300']);
        return response()->json($this->docs->cancel($id, (int) $d['version'], $d['reason']));
    }

    public function done(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'done' => 'required|boolean']);
        return response()->json($this->docs->setDone($id, (int) $d['version'], (bool) $d['done']));
    }

    public function route(Request $r, int $id)
    {
        $d = $r->validate(['user_ids' => 'required|array|min:1|max:100', 'user_ids.*' => 'integer|min:1', 'note' => 'nullable|string|max:300']);
        return response()->json($this->docs->route($id, $d['user_ids'], $d['note'] ?? null));
    }

    public function unroute(Request $r, int $id, int $routeId)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        return response()->json($this->docs->unroute($id, $routeId, (int) $d['version']));
    }

    public function inbox(Request $r)
    {
        $p = $r->validate(['state' => ['nullable', Rule::in(['all', 'unread', 'pending'])], 'page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($this->docs->inbox($p));
    }

    public function acknowledge(Request $r, int $routeId)
    {
        $d = $r->validate(['note' => 'nullable|string|max:500']);
        return response()->json($this->docs->acknowledge($routeId, $d['note'] ?? null));
    }

    public function clerks() { return response()->json($this->docs->clerks()); }

    public function setClerks(Request $r)
    {
        $d = $r->validate(['version' => 'required|integer|min:0', 'clerk_user_ids' => 'present|array|max:200', 'clerk_user_ids.*' => 'integer|min:1']);
        return response()->json($this->docs->setClerks($d['clerk_user_ids'], (int) $d['version']));
    }

    public function upload(Request $r, int $id)
    {
        $d = $r->validate(['file' => 'required|file']);
        return Idempotency::run($r, fn () => response()->json($this->docs->upload($id, $d['file']), 201));
    }

    public function download(int $fileId) { return $this->docs->download($fileId); }

    public function deleteFile(Request $r, int $fileId)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        return response()->json($this->docs->deleteFile($fileId, (int) $d['version']));
    }

    public function print(Request $r)
    {
        $d = $r->validate(['kind' => ['required', Rule::in(array_keys(Documents::KINDS))], 'year_be' => 'required|integer|min:2400|max:2700', 'month' => 'required|integer|min:1|max:12']);
        return response(view('documents::print', $this->docs->printData($d['kind'], (int) $d['year_be'], (int) $d['month']))->render())
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }
}
