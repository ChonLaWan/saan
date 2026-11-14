<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('cms_media',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('school_id');$t->foreignId('uploaded_by')->constrained('users');
            $t->string('path');$t->string('mime',80);$t->unsignedInteger('bytes');$t->unsignedInteger('width');$t->unsignedInteger('height');$t->string('alt',200)->default('');$t->timestamps();
            $t->index(['school_id','id']);
        });
        Schema::create('cms_themes',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('school_id')->unique();$t->json('draft');$t->json('published');
            $t->unsignedInteger('version')->default(1);$t->unsignedInteger('published_version')->default(1);$t->timestamps();
        });
        Schema::table('posts',function(Blueprint $t){
            $t->string('slug',190)->nullable();$t->string('excerpt',300)->default('');$t->string('category',30)->default('news');
            $t->foreignId('cover_media_id')->nullable()->constrained('cms_media');$t->boolean('is_pinned')->default(false);
            $t->json('draft_payload')->nullable();$t->unsignedInteger('version')->default(1);$t->unique(['school_id','slug']);
        });
        foreach(DB::table('posts')->select('id')->get()as$post)DB::table('posts')->where('id',$post->id)->update(['slug'=>'news-'.$post->id]);
        Schema::create('cms_post_revisions',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('school_id');$t->foreignId('post_id')->constrained('posts');$t->unsignedInteger('version');
            $t->json('snapshot');$t->foreignId('published_by')->constrained('users');$t->timestamp('created_at');$t->unique(['post_id','version']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('cms_post_revisions');
        Schema::table('posts',function(Blueprint $t){$t->dropUnique(['school_id','slug']);$t->dropConstrainedForeignId('cover_media_id');$t->dropColumn(['slug','excerpt','category','is_pinned','draft_payload','version']);});
        Schema::dropIfExists('cms_themes');Schema::dropIfExists('cms_media');
    }
};
