<?php

namespace Tests\Feature;

use App\Http\Controllers\WebsiteMediaController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Round-13 audit regressions that need no module-specific fixture. */
class PublicMediaFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private function clone(int $id, string $status, int $version, ?int $cover): void
    {
        $row = (array) DB::table('posts')->orderBy('id')->first();
        $row['id'] = $id; $row['status'] = $status; $row['version'] = $version; $row['cover_media_id'] = null; $row['metadata'] = json_encode($cover ? ['placement' => 'hero', 'mobile_media_id' => $cover] : []); $row['deleted_at'] = null;
        foreach ($row as $k => $v) if (is_string($v) && (str_contains($k, 'slug') || str_contains($k, 'uuid') || $k === 'public_id')) $row[$k] = $v.'-'.$id;
        DB::table('posts')->insert($row);
    }

    public function test_media_whitelist_follows_a_swap_of_two_posts_with_equal_version_totals(): void
    {
        config(['school.demo' => true]);
        $this->seed();
        DB::table('posts')->where('id', 1)->update(['version' => 1]);
        $this->clone(2, 'published', 3, 9002);   // A: leaves
        $this->clone(3, 'draft', 2, 9003);       // C: joins as version 3
        $this->clone(9, 'published', 1, null);   // keeps MAX(id) unchanged
        $ctl = app(WebsiteMediaController::class);
        $before = $ctl->publicIds(1);
        $this->assertContains(9002, $before);
        $this->assertNotContains(9003, $before);
        DB::table('posts')->where('id', 2)->update(['status' => 'draft']);
        DB::table('posts')->where('id', 3)->update(['status' => 'published', 'version' => 3]);
        // COUNT, SUM(version) and MAX(id) are identical to the first state
        $after = $ctl->publicIds(1);
        $this->assertNotContains(9002, $after, 'a withdrawn post image must stop being public at once');
        $this->assertContains(9003, $after);
    }
}