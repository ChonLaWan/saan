<?php

namespace Saan\Modules\Personnel\Http;

use App\Support\Saan\Settings;
use Illuminate\Http\Request;
use Saan\Modules\Personnel\Folders;
use Saan\Modules\Personnel\Staff;
use Saan\Modules\Personnel\Support;

/** HR register, folder settings and the public directory. Everything except directory() is admin/principal only. */
class StaffController
{
    /**
     * GET /api/personnel/directory: no login, public-safe fields only (see Staff::directory).
     * Note: the core SchoolBoundary middleware forces Cache-Control: no-store on every module route, so a reverse proxy or the
     * consuming theme should cache this response itself (it is a cheap indexed query, throttled per IP).
     */
    public function directory()
    {
        return response()->json(['data' => Staff::directory()]);
    }

    /** GET /portal/staff-photo/{file}: streams the private 480 px derivative to the public (directory-visible staff) or to an admin/principal session. */
    public function photoFile(Request $r, string $file)
    {
        $u = $r->user();
        $isStaff = $u && in_array($u->role, ['admin', 'principal'], true);
        $path = Staff::photoPathFor($file, (bool) $isStaff);
        abort_unless($path, 404);
        // the public derivative opts out of SchoolBoundary's blanket no-store with the marker header (the middleware strips it); the staff variant stays no-store
        return Support::disk()->response($path, $file, ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $isStaff ? 'private, max-age=300' : 'public, max-age=3600'] + ($isStaff ? [] : ['X-Saan-Public-Cache' => '1']), 'inline');
    }

    public function index(Request $r)
    {
        return response()->json(Staff::list($r->validate(['q' => 'nullable|string|max:80', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100'])));
    }

    public function show(int $teacherId) { return response()->json(Staff::detail($teacherId)); }

    public function save(Request $r, int $teacherId)
    {
        $d = $r->validate([
            'version' => 'nullable|integer|min:1', 'position' => 'nullable|string|max:120', 'academic_standing' => 'nullable|string|max:120', 'department' => 'nullable|string|max:120',
            'work_group' => 'nullable|string|max:120', 'start_date' => 'nullable|date_format:Y-m-d|after:1899-12-31|before:tomorrow',
            'education' => 'nullable|array|max:10', 'education.*' => 'array:level,degree,major,institution,year',
            'education.*.level' => 'nullable|string|max:80', 'education.*.degree' => 'nullable|string|max:160', 'education.*.major' => 'nullable|string|max:160',
            'education.*.institution' => 'nullable|string|max:200', 'education.*.year' => 'nullable|integer|between:2400,2700',
            'show_in_directory' => 'sometimes|boolean', 'directory_order' => 'sometimes|integer|between:-9999,9999',
        ]);
        $version = isset($d['version']) ? (int) $d['version'] : null;
        return response()->json(Staff::save($teacherId, $version, $d));
    }

    public function photo(Request $r, int $teacherId)
    {
        $r->validate(['version' => 'required|integer|min:1', 'photo' => 'required|file']);
        return response()->json(Staff::setPhoto($teacherId, (int) $r->input('version'), $r->file('photo')));
    }

    public function removePhoto(Request $r, int $teacherId)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        return response()->json(Staff::removePhoto($teacherId, (int) $d['version']));
    }

    public function summary() { return response()->json(Staff::summary()); }

    public function putSettings(Request $r)
    {
        $d = $r->validate(['teacher_quota_mb' => 'required|integer|min:0|max:1048576']);
        Settings::put('personnel.teacher_quota_mb', (int) $d['teacher_quota_mb']);
        \App\Support\Saan\Mutation::audit('personnel.settings.updated', 'saan_settings', null, ['teacher_quota_mb' => (int) $d['teacher_quota_mb']]);
        return response()->json(['teacher_quota_mb' => Support::teacherQuotaMb()]);
    }

    public function createFolder(Request $r)
    {
        return response()->json(Folders::create($r->validate($this->folderRules(true))), 201);
    }

    public function updateFolder(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1'] + $this->folderRules(false));
        $version = (int) $d['version']; unset($d['version']);
        return response()->json(Folders::update($id, $version, $d));
    }

    public function deleteFolder(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        Folders::delete($id, (int) $d['version']);
        return response()->json(['deleted' => true]);
    }

    private function folderRules(bool $creating): array
    {
        return [
            'label' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'], 'code' => $creating ? ['nullable', 'regex:/^[a-z0-9][a-z0-9-]{0,39}$/D'] : ['prohibited'],
            'retention_years' => 'nullable|integer|between:1,100', 'max_file_mb' => 'sometimes|integer|between:1,2048',
            'quota_mb_per_teacher' => 'nullable|integer|between:1,1048576', 'sort' => 'sometimes|integer|between:0,100000',
        ];
    }
}
