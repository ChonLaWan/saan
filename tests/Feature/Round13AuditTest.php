<?php

namespace Tests\Feature;

use App\Support\Saan\KeyCollation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Saan\Modules\LearningLinks\Links;
use Saan\Modules\WebNotices\NoticeRepository;
use Tests\TestCase;

/** Round-13 audit regressions: keyed hashes across key rotation, keep-data migrations, collation repair coverage. */
class Round13AuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_notice_access_codes_survive_an_app_key_rotation(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'saan.hash_key' => '', 'app.previous_keys' => []]);
        $stored = NoticeRepository::hashCode('รหัส-1234');
        $this->assertTrue(NoticeRepository::checkCode($stored, 'รหัส-1234'));
        $old = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32)), 'app.previous_keys' => [$old]]);
        $this->assertTrue(NoticeRepository::checkCode($stored, 'รหัส-1234'), 'rotation with APP_PREVIOUS_KEYS must keep the code valid');
        $this->assertFalse(NoticeRepository::checkCode($stored, 'ผิด'));
        // a dedicated SAAN_HASH_KEY set later does not orphan old hashes either
        config(['saan.hash_key' => 'dedicated-secret']);
        $this->assertTrue(NoticeRepository::checkCode($stored, 'รหัส-1234'));
        $fresh = NoticeRepository::hashCode('รหัส-1234');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'app.previous_keys' => []]);
        $this->assertTrue(NoticeRepository::checkCode($fresh, 'รหัส-1234'), 'hashes made with SAAN_HASH_KEY never depend on APP_KEY');
    }

    public function test_class_link_codes_survive_an_app_key_rotation(): void
    {
        config(['school.school_id' => 1, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'saan.hash_key' => '', 'app.previous_keys' => []]);
        $stored = Links::hashCode(5, 'ab12-cd34');
        $this->assertTrue(Links::codeMatches($stored, 5, 'AB12CD34'));
        $old = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32)), 'app.previous_keys' => [$old]]);
        $this->assertTrue(Links::codeMatches($stored, 5, 'AB12CD34'));
        $this->assertFalse(Links::codeMatches($stored, 6, 'AB12CD34'));
        $this->assertFalse(Links::codeMatches($stored, 5, 'AB12CD35'));
    }

    public function test_keep_data_modules_never_drop_their_tables_on_rollback(): void
    {
        $this->assertTrue(Schema::hasTable('lrn_links') && Schema::hasTable('con_projects') && Schema::hasTable('con_members'));
        foreach (['modules/learning-links/database/migrations/2026_11_11_400001_learning_links_create_tables.php', 'modules/constructionism/database/migrations/2026_11_11_410001_constructionism_create_tables.php',
            'modules/activities/database/migrations/2026_11_13_800003_activities_wipe_election_key_secret.php'] as $file) {
            (require base_path($file))->down();
        }
        foreach (['lrn_links', 'lrn_access', 'con_projects', 'con_members', 'con_reflections', 'con_artifacts', 'con_logs'] as $t) $this->assertTrue(Schema::hasTable($t), $t.' must survive down()');
    }

    public function test_every_unique_key_column_that_needs_a_binary_collation_is_listed(): void
    {
        foreach (['con_projects' => 'title_key', 'prj_projects' => 'code_key', 'act_clubs' => 'name_key', 'mnt_assets' => 'asset_code_key', 'ld_leave_types' => 'label_key'] as $table => $col) {
            $this->assertContains($col, KeyCollation::KEY_COLUMNS[$table] ?? [], $table);
        }
        $this->assertSame(0, KeyCollation::repair()); // the migrations already set the binary collation: nothing to change and no error
    }

    public function test_migration_that_wipes_election_key_secret_and_rerandomises_voter_keys(): void
    {
        \Illuminate\Support\Facades\DB::table('act_elections')->insert(['school_id' => 1, 'title' => 'เลือกตั้ง', 'opens_at' => now(), 'closes_at' => now()->addDay(), 'status' => 'open', 'key_secret' => str_repeat('a', 64), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $eid = (int) \Illuminate\Support\Facades\DB::table('act_elections')->value('id');
        $derived = hash_hmac('sha256', 'act-ballot|'.$eid.'|7', str_repeat('a', 64).'|'.config('app.key'));
        \Illuminate\Support\Facades\DB::table('act_ballots')->insert(['id' => bin2hex(random_bytes(16)), 'election_id' => $eid, 'voter_key' => $derived, 'candidate_id' => null, 'cast_at' => '2026-11-02']);
        (require base_path('modules/activities/database/migrations/2026_11_13_800003_activities_wipe_election_key_secret.php'))->up();
        $this->assertNull(\Illuminate\Support\Facades\DB::table('act_elections')->where('id', $eid)->value('key_secret'));
        $this->assertNotSame($derived, \Illuminate\Support\Facades\DB::table('act_ballots')->value('voter_key'));
    }
}