<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cms_media',function(Blueprint $t){$t->foreignId('source_media_id')->nullable()->constrained('cms_media')->restrictOnDelete();});
        Schema::create('cms_redirects',function(Blueprint $t){$t->id();$t->unsignedBigInteger('school_id');$t->string('source_path',255);$t->string('target_path',1000);$t->unsignedSmallInteger('status_code')->default(301);$t->boolean('is_active')->default(true);$t->unsignedInteger('version')->default(1);$t->timestamps();$t->unique(['school_id','source_path']);});
        Schema::create('cms_import_items',function(Blueprint $t){$t->id();$t->unsignedBigInteger('school_id');$t->string('source',180);$t->string('legacy_id',80);$t->foreignId('post_id')->constrained('posts');$t->string('source_hash',64);$t->timestamps();$t->unique(['school_id','source','legacy_id'],'cms_import_identity');});
    }
    public function down(): void {Schema::dropIfExists('cms_import_items');Schema::dropIfExists('cms_redirects');Schema::table('cms_media',fn(Blueprint $t)=>$t->dropConstrainedForeignId('source_media_id'));}
};
