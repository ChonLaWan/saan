<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{Schema::create('school_mail_settings',function(Blueprint $t){$t->id();$t->unsignedBigInteger('school_id')->unique();$t->longText('encrypted_payload');$t->unsignedInteger('version')->default(1);$t->timestamp('last_test_at')->nullable();$t->string('last_test_status',24)->nullable();$t->timestamps();});}
    public function down():void{Schema::dropIfExists('school_mail_settings');}
};
