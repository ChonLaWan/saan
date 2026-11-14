<?php

namespace Saan\Modules\WebSocial;

use App\Domain\Cms\WebsiteContent;
use App\Domain\SchoolOperations;
use Illuminate\Support\Facades\DB;

class SocialFeed
{
    public function __construct(private SchoolOperations $ops, private WebsiteContent $content) {}

    public static function defaults(): array
    {
        return ['facebook_url' => '', 'heading' => 'ข่าวจากเพจ Facebook ของโรงเรียน', 'height' => 640, 'enabled' => false, 'version' => 0];
    }

    /** Only public Facebook page addresses; no tokens are stored or needed. */
    public static function validUrl(string $url): bool
    {
        return (bool) preg_match('~^https://(www\.|m\.|web\.)?(facebook|fb)\.com/[A-Za-z0-9.\-_/%?=&]{1,200}$~D', $url) && !str_contains($url, 'access_token');
    }

    public static function forSchool(int $school): array
    {
        $row = DB::table('social_feeds')->where('school_id', $school)->first();
        return $row ? ['facebook_url' => $row->facebook_url, 'heading' => $row->heading, 'height' => (int) $row->height, 'enabled' => (bool) $row->enabled, 'version' => (int) $row->version] : self::defaults();
    }

    public static function pluginUrl(array $feed, int $width = 500): string
    {
        return 'https://www.facebook.com/plugins/page.php?'.http_build_query(['href' => $feed['facebook_url'], 'tabs' => 'timeline', 'width' => $width, 'height' => $feed['height'], 'small_header' => 'false', 'adapt_container_width' => 'true', 'hide_cover' => 'false', 'show_facepile' => 'false']);
    }

    public function read(): array { $this->content->authorize(); return self::forSchool($this->ops->school()); }

    public function save(array $data, int $version): array
    {
        $this->content->authorize();
        abort_if($data['enabled'] && !self::validUrl($data['facebook_url']), 422, 'กรอกลิงก์เพจ Facebook แบบ https://www.facebook.com/ชื่อเพจ');
        return DB::transaction(function () use ($data, $version) {
            $school = $this->ops->school();
            DB::table('social_feeds')->insertOrIgnore(['school_id' => $school, 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('social_feeds')->where('school_id', $school)->lockForUpdate()->first();
            abort_if((int) $row->version !== $version, 409, 'มีการแก้การตั้งค่าแล้ว กรุณาโหลดใหม่');
            DB::table('social_feeds')->where('id', $row->id)->update(['facebook_url' => $data['facebook_url'], 'heading' => $data['heading'], 'height' => $data['height'], 'enabled' => $data['enabled'], 'version' => $version + 1, 'updated_at' => now()]);
            $this->ops->audit('social.saved', 'social_feeds', $row->id, ['enabled' => $data['enabled'], 'version' => $version + 1]);
            return self::forSchool($school);
        });
    }
}
