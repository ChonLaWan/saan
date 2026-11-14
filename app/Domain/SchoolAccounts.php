<?php

namespace App\Domain;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class SchoolAccounts
{
    public const ROLES = [
        'admin' => 'ผู้ดูแลระบบ', 'editor' => 'ผู้ดูแลเว็บไซต์',
        'principal' => 'ผู้อำนวยการ', 'teacher' => 'ครู',
    ];

    public function __construct(private SchoolOperations $ops) {}

    public function authorize(): void
    {
        $this->ops->role(['admin']);
    }

    public function save(array $data, ?int $id = null): User
    {
        $this->authorize();
        if ($data['teacher_id'] ?? null) $this->ops->record('teachers', $data['teacher_id']);
        if ($data['classroom_id'] ?? null) $this->ops->record('classrooms', $data['classroom_id']);

        return DB::transaction(function () use ($data, $id) {
            // Lock the same school user set in a stable order: concurrent demotions cannot remove every admin.
            $schoolUsers = User::where('school_id', $this->ops->school())->orderBy('id')->lockForUpdate()->get();
            $user = $id ? ($schoolUsers->firstWhere('id', $id) ?? abort(404)) : new User;
            if ($id) {
                abort_if($user->is_demo, 422, 'บัญชีสาธิตแก้ไขไม่ได้');
                abort_if($user->version !== (int) $data['version'], 409, 'บัญชีนี้มีการแก้ไขแล้ว กรุณาโหลดข้อมูลล่าสุด');
                abort_if($id === auth()->id() && (!$data['is_active'] || $data['role'] !== 'admin'), 422, 'ไม่สามารถปิดบัญชีหรือลดสิทธิ์ของตนเอง');
                $activeAdmins = $schoolUsers->where('role', 'admin')->where('is_active', true)->where('is_demo', false);
                abort_if($user->role === 'admin' && $user->is_active && (!$data['is_active'] || $data['role'] !== 'admin') && $activeAdmins->count() <= 1, 422, 'ต้องเหลือผู้ดูแลระบบที่ใช้งานได้อย่างน้อยหนึ่งคน');
            }

            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->role = $data['role'];
            $user->school_id = $this->ops->school();
            $user->is_active = $data['is_active'];
            $user->teacher_id = $data['teacher_id'] ?? null;
            $user->classroom_id = $data['classroom_id'] ?? null;
            if ($id) {
                $user->auth_version++;
                $user->version++;
            } else {
                $user->password = $data['password'];
                $user->is_demo = false;
            }
            $user->save();
            $user->refresh();
            $this->ops->audit($id ? 'account.updated' : 'account.created', 'users', $user->id, ['role' => $user->role, 'is_active' => $user->is_active]);
            return $user;
        });
    }
}
