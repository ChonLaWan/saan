<?php

namespace Saan\Modules\Registry\Http;

use App\Support\Saan\Idempotency;
use App\Support\Saan\Mutation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Saan\Modules\Registry\Csv;
use Saan\Modules\Registry\Identity;
use Saan\Modules\Registry\RegistryRepository as Repo;

class RegistryController
{
    public function __construct(private Repo $repo) {}

    public function index(Request $r)
    {
        $f = $r->validate([
            'classroom_id' => 'nullable|string|max:12', 'status' => ['nullable', Rule::in(Repo::STATUS)], 'nationality_status' => ['nullable', Rule::in(Repo::NATIONALITY)],
            'pending_documents' => 'nullable|boolean', 'q' => 'nullable|string|max:80', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:200',
        ]);
        if (isset($f['classroom_id']) && $f['classroom_id'] !== '' && $f['classroom_id'] !== 'none' && !ctype_digit($f['classroom_id'])) abort(422, 'ห้องเรียนไม่ถูกต้อง');
        return response()->json($this->repo->list($f));
    }

    public function classrooms() { return response()->json(['data' => $this->repo->classrooms()]); }

    public function show(int $id)
    {
        $d = $this->repo->detail($id);
        // the answer carries decrypted guardian phones and pickup restrictions: leave a trace of who opened which child
        Mutation::audit('registry.student.viewed', 'students', $id, ['guardians' => count($d['guardians']), 'pickup_restricted' => count($d['pickup_restrictions']) > 0]);
        return response()->json($d)->header('Cache-Control', 'no-store, private');
    }

    public function unlinkedGuardians() { return response()->json(['data' => $this->repo->unlinkedGuardians()]); }

    public function deleteGuardian(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $this->repo->deleteGuardian($id, (int) $d['version']);
        return response()->json(['deleted' => true]);
    }

    private function studentRules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        return [
            'code' => [$req, 'string', 'max:40'], 'prefix' => 'nullable|string|max:30', 'first_name' => [$req, 'string', 'max:120'], 'last_name' => 'nullable|string|max:120',
            'nickname' => 'nullable|string|max:60', 'sex' => ['nullable', Rule::in(['M', 'F'])], 'birth_date' => 'nullable|date_format:Y-m-d|after:1899-12-31|before:tomorrow',
            'birth_year_be' => 'nullable|integer|between:2400,2700', 'nationality_status' => ['sometimes', Rule::in(Repo::NATIONALITY)],
            'preferred_language' => 'nullable|string|max:40', 'needs_interpreter' => 'sometimes|boolean', 'status' => ['sometimes', Rule::in(Repo::STATUS)],
            'admitted_on' => 'nullable|date_format:Y-m-d', 'admission_type' => ['nullable', Rule::in(Repo::ADMISSION)], 'previous_school' => 'nullable|string|max:200',
            'roll_number' => 'nullable|integer|between:1,999', 'classroom_id' => 'nullable|integer|min:1',
        ];
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->studentRules(true));
        return Idempotency::run($r, fn () => response()->json($this->repo->create($d), 201));
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1'] + $this->studentRules(false));
        $version = (int) $d['version']; unset($d['version']);
        return response()->json($this->repo->update($id, $version, $d));
    }

    public function addGuardian(Request $r, int $id)
    {
        $d = $r->validate([
            'guardian_id' => 'nullable|integer|min:1', 'guardian' => 'nullable|array:prefix,first_name,last_name,phone,email,line_id,occupation',
            'guardian.prefix' => 'nullable|string|max:30', 'guardian.first_name' => 'required_with:guardian|string|max:120', 'guardian.last_name' => 'nullable|string|max:120',
            'guardian.phone' => 'nullable|string|max:40', 'guardian.email' => 'nullable|email|max:190', 'guardian.line_id' => 'nullable|string|max:80', 'guardian.occupation' => 'nullable|string|max:120',
            'relationship' => ['nullable', Rule::in(Repo::RELATIONSHIP)], 'is_primary' => 'nullable|boolean', 'is_emergency_contact' => 'nullable|boolean',
            'can_pick_up' => 'nullable|boolean', 'pickup_note' => 'nullable|string|max:300',
        ]);
        if (array_key_exists('can_pick_up', $d) && $d['can_pick_up'] !== null) $d['can_pick_up'] = (bool) $d['can_pick_up']; else unset($d['can_pick_up']);
        // the answer carries decrypted guardian phones: the replay cache keeps only the status and rebuilds the body from the database
        return Idempotency::run($r, fn () => response()->json($this->repo->addGuardian($id, $d), 201)->header('Cache-Control', 'no-store, private'),
            fn () => response()->json($this->repo->detail($id), 201)->header('Cache-Control', 'no-store, private'));
    }

    public function updateGuardian(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'prefix' => 'nullable|string|max:30', 'first_name' => 'sometimes|string|max:120', 'last_name' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:190', 'line_id' => 'nullable|string|max:80', 'occupation' => 'nullable|string|max:120']);
        $version = (int) $d['version']; unset($d['version']);
        return response()->json($this->repo->updateGuardian($id, $version, $d));
    }

    public function updateLink(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'relationship' => ['sometimes', Rule::in(Repo::RELATIONSHIP)], 'is_primary' => 'sometimes|boolean',
            'is_emergency_contact' => 'sometimes|boolean', 'can_pick_up' => 'sometimes|boolean', 'pickup_note' => 'nullable|string|max:300']);
        $version = (int) $d['version']; unset($d['version']);
        return response()->json($this->repo->updateLink($id, $version, $d));
    }

    public function deleteLink(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        return response()->json($this->repo->deleteLink($id, (int) $d['version']));
    }

    // ---------- identifiers ----------

    public function addIdentifier(Request $r)
    {
        $d = $r->validate(['person_type' => ['required', Rule::in(['student', 'guardian'])], 'person_id' => 'required|integer|min:1', 'kind' => ['required', Rule::in(Identity::KINDS)],
            'value' => 'nullable|string|max:60', 'verification_state' => ['nullable', Rule::in(['unverified', 'verified', 'rejected'])], 'note' => 'nullable|string|max:300',
            'issuer' => 'nullable|string|max:10', 'expires_on' => 'nullable|date_format:Y-m-d']);
        if (empty($d['verification_state'])) unset($d['verification_state']);
        return Idempotency::run($r, fn () => response()->json($this->repo->addIdentifier($d), 201));
    }

    public function updateIdentifier(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'kind' => ['sometimes', Rule::in(Identity::KINDS)], 'value' => 'nullable|string|max:60',
            'verification_state' => ['sometimes', Rule::in(['unverified', 'verified', 'rejected'])], 'note' => 'nullable|string|max:300',
            'issuer' => 'nullable|string|max:10', 'expires_on' => 'nullable|date_format:Y-m-d']);
        $version = (int) $d['version']; unset($d['version']);
        return response()->json($this->repo->updateIdentifier($id, $version, $d));
    }

    public function deleteIdentifier(Request $r, int $id)
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);
        $this->repo->deleteIdentifier($id, (int) $d['version']);
        return response()->json(['deleted' => true]);
    }

    public function reveal(int $id) { return response()->json($this->repo->reveal($id))->header('Cache-Control', 'no-store, private'); }

    // ---------- export ----------

    /** Class list for printing: no identifiers, no documents. A teacher can only export the homeroom classroom. */
    public function exportClass(Request $r)
    {
        $d = $r->validate(['classroom_id' => 'required|integer|min:1']);
        abort_unless(collect($this->repo->classrooms())->contains('id', (int) $d['classroom_id']), 404, 'ไม่พบห้องเรียน');
        $list = $this->repo->list(['classroom_id' => (string) $d['classroom_id'], 'per_page' => 200, 'status' => 'studying'])['data'];
        $name = $this->repo->classroomName((int) $d['classroom_id']);
        Mutation::audit('registry.class_list.exported', 'classrooms', (int) $d['classroom_id'], ['rows' => count($list)]);
        $labels = ['thai' => 'ไทย', 'foreign' => 'ต่างชาติ', 'stateless' => 'ไร้สัญชาติ', 'undetermined' => 'รอตรวจสอบ'];
        return response()->streamDownload(function () use ($list, $labels, $name) {
            echo "\xEF\xBB\xBF", Csv::line(['เลขที่', 'รหัสนักเรียน', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'ชื่อเล่น', 'เพศ', 'สถานะสัญชาติ', 'ภาษา', 'ต้องการล่าม', 'ห้ามรับกลับบ้านบางคน', 'ห้องเรียน']);
            foreach ($list as $s) echo Csv::line([$s['roll_number'], $s['code'], $s['prefix'], $s['first_name'] ?? $s['name'], $s['last_name'], $s['nickname'], $s['sex'],
                $labels[$s['nationality_status']] ?? '', $s['preferred_language'], $s['needs_interpreter'] ? 'ใช่' : '', $s['pickup_restricted'] ? 'ใช่' : '', $name]);
        }, 'class-list-'.$d['classroom_id'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
