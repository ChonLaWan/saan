<?php

namespace Saan\Modules\Registry\Http;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Saan\Modules\Registry\DocumentService;

class DocumentController
{
    public function __construct(private DocumentService $docs) {}

    public function store(Request $r, int $id)
    {
        $d = $r->validate(['file' => 'required|file', 'kind' => ['required', Rule::in(DocumentService::KINDS)], 'label' => 'nullable|string|max:160']);
        return response()->json($this->docs->upload($id, $d['file'], $d['kind'], $d['label'] ?? null), 201);
    }

    public function download(int $id) { return $this->docs->download($id); }

    public function update(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'review_state' => ['sometimes', Rule::in(DocumentService::REVIEW)], 'label' => 'sometimes|string|max:160',
            'kind' => ['sometimes', Rule::in(DocumentService::KINDS)]]);
        $version = (int) $d['version']; unset($d['version']);
        return response()->json($this->docs->review($id, $version, $d));
    }

    public function destroy(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $this->docs->delete($id, (int) $d['version']);
        return response()->json(['deleted' => true]);
    }
}
