<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('social_feeds')) Schema::create('social_feeds', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id')->unique();
            $t->string('facebook_url', 300)->default(''); $t->string('heading', 120)->default('ข่าวจากเพจ Facebook ของโรงเรียน');
            $t->unsignedSmallInteger('height')->default(640); $t->boolean('enabled')->default(false);
            $t->unsignedInteger('version')->default(0); $t->timestamps();
        });
    }

    public function down(): void {}
};
