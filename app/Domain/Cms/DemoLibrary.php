<?php
namespace App\Domain\Cms;

use Illuminate\Support\Facades\Storage;

class DemoLibrary
{
    public function __construct(private WebsiteContent $content) {}
    public const VERSION='2026-11-05.2';
    public static function prefixFor(int $schoolId): string {return 'demo-library/'.$schoolId.'/'.self::VERSION;}
    public function prefix(): string {return self::prefixFor((int)config('school.school_id'));}
    /** Starter data that ships the website sample (and with it this library): "demo", and the full trial "full" (storage/installed.json mode, or SCHOOL_STARTER_DATA). */
    private static function sampleInstall(): bool {return in_array(config('school.starter_data'),['demo','full'],true);}
    /** OPS-009: one predicate for menu, coverage report and access. */
    public static function available(): bool
    {
        return ((bool)config('school.demo')&&(bool)config('school.local_only'))
            ||(self::sampleInstall()&&is_file(storage_path('app/private/'.self::prefixFor((int)config('school.school_id')).'/catalog.json')));
    }
    public function catalog(): array
    {
        $this->content->authorize();
        abort_unless((config('school.demo')&&config('school.local_only'))||self::sampleInstall(),404);
        $disk=Storage::disk('local');$path=$this->prefix().'/catalog.json';
        abort_unless($disk->exists($path),404,'ยังไม่ได้ติดตั้งชุดข้อมูลสมมติ');
        return json_decode($disk->get($path),true,512,JSON_THROW_ON_ERROR);
    }
    public function file(string $key): array
    {
        $catalog=$this->catalog();$file=$catalog['files'][$key]??abort(404);
        abort_unless(preg_match('~^(documents|previews|datasets|concepts|artwork|videos)/[A-Za-z0-9._-]+$~D',$file['path']),404);
        $disk=Storage::disk('local');$path=$this->prefix().'/'.$file['path'];abort_unless($disk->exists($path),404);
        abort_unless(hash_equals($file['sha256'],hash_file('sha256',$disk->path($path))),409,'ไฟล์ตัวอย่างเปลี่ยนจากชุดที่ตรวจสอบแล้ว');
        return $file+['absolute'=>$disk->path($path)];
    }
}
