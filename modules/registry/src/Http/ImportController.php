<?php

namespace Saan\Modules\Registry\Http;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Saan\Modules\Registry\ImportService;

class ImportController
{
    public function __construct(private ImportService $import) {}

    public function index() { return response()->json(['data' => $this->import->batches()]); }

    public function store(Request $r)
    {
        $d = $r->validate(['kind' => ['required', Rule::in(ImportService::KINDS)], 'file' => 'required|file']);
        $file = $d['file'];
        abort_unless($file->isValid(), 422, 'อัปโหลดไฟล์ไม่สำเร็จ');
        abort_if($file->getSize() > max(1, (int) config('saan.storage.max_upload_mb')) * 1048576, 422, 'ไฟล์ใหญ่เกินกำหนด');
        return response()->json($this->import->preview($d['kind'], $file->getClientOriginalName(), (string) file_get_contents($file->getRealPath())), 201);
    }

    public function show(Request $r, int $id) { return response()->json($this->import->batch($id, (int) $r->query('page', 1))); }

    public function commit(int $id) { return response()->json($this->import->commit($id)); }

    public function rollback(int $id)
    {
        $res = $this->import->rollback($id);
        if (isset($res['blocking'])) {
            return response()->json(['message' => 'ย้อนกลับไม่ได้ เพราะมีแถวที่ถูกแก้ไขหรือมีข้อมูลที่เกี่ยวข้องแล้ว', 'blocking' => $res['blocking']], 409);
        }
        return response()->json($res);
    }
}
