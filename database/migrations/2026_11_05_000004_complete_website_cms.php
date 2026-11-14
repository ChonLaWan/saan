<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $t) {
            $t->string('content_type', 24)->default('article');
            $t->json('metadata')->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->foreignId('scheduled_by')->nullable()->constrained('users');
            $t->timestamp('deleted_at')->nullable();
            $t->index(['school_id','content_type','status','deleted_at'], 'cms_content_listing');
        });
        Schema::table('cms_media', function (Blueprint $t) {
            $t->string('original_name',200)->default('');
            $t->string('folder',80)->default('ทั่วไป');
            $t->unsignedInteger('version')->default(1);
        });
        Schema::create('cms_categories', function (Blueprint $t) {
            $t->id();$t->unsignedBigInteger('school_id');$t->string('slug',30);$t->string('label',80);
            $t->unsignedInteger('version')->default(1);$t->timestamps();$t->unique(['school_id','slug']);
        });
        Schema::create('cms_site_settings', function (Blueprint $t) {
            $t->id();$t->unsignedBigInteger('school_id')->unique();$t->json('draft');$t->json('published');
            $t->unsignedInteger('version')->default(0);$t->unsignedInteger('published_version')->default(0);$t->timestamps();
        });
        Schema::create('cms_messages', function (Blueprint $t) {
            $t->id();$t->unsignedBigInteger('school_id');$t->string('name',100);$t->string('email',254);
            $t->string('subject',160);$t->text('message');$t->string('status',16)->default('unread');
            $t->unsignedInteger('version')->default(1);$t->timestamps();$t->index(['school_id','status','created_at']);
        });
        foreach (DB::table('users')->distinct()->pluck('school_id') as $school) {
            foreach (['news'=>'ข่าวประชาสัมพันธ์','activity'=>'กิจกรรมโรงเรียน','admission'=>'รับสมัครนักเรียน','procurement'=>'จัดซื้อจัดจ้าง','jobs'=>'รับสมัครงาน','general'=>'ทั่วไป'] as $slug=>$label) {
                DB::table('cms_categories')->insert(['school_id'=>$school,'slug'=>$slug,'label'=>$label,'created_at'=>now(),'updated_at'=>now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_messages');Schema::dropIfExists('cms_site_settings');Schema::dropIfExists('cms_categories');
        Schema::table('cms_media',fn(Blueprint $t)=>$t->dropColumn(['original_name','folder','version']));
        Schema::table('posts',function(Blueprint $t){$t->dropIndex('cms_content_listing');$t->dropConstrainedForeignId('scheduled_by');$t->dropColumn(['content_type','metadata','scheduled_at','deleted_at']);});
    }
};
