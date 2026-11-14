<?php

namespace App\Domain\Cms;

/**
 * F5a public-site extras kept in the site settings (key "extras"): popular-news block, view counter display, chat buttons and the map block.
 *
 * Everything that can reach a page is validated twice: strictly when staff save (problem()) and again, leniently, whenever the settings are read
 * (normalize()), so a value that is not clean never reaches a template, whatever the stored JSON says.
 *
 * Chat links are plain links opened on click: only the exact hosts below, https, a safe id. No script, SDK, iframe, pixel or font is ever loaded for them.
 * The map is a static placeholder; the provider iframe is inserted only after a click, and only these frame sources may ever be named in a CSP frame-src.
 */
final class SiteExtras
{
    public const DAYS = [7, 30];
    public const PLACEMENTS = ['floating', 'footer', 'both'];
    public const PROVIDERS = ['google' => 'Google Maps', 'osm' => 'OpenStreetMap'];
    /** The only frame sources that a page may ever get (CSP frame-src); path-limited on purpose. */
    public const FRAME_SOURCES = ['google' => 'https://www.google.com/maps', 'osm' => 'https://www.openstreetmap.org/export/embed.html'];
    public const KEYS = ['popular', 'show_views', 'chat', 'map'];

    public static function defaults(): array
    {
        return [
            'popular' => ['days' => 7, 'limit' => 5],
            'show_views' => false,
            'chat' => ['messenger' => '', 'line' => '', 'placement' => 'floating'],
            'map' => ['provider' => 'google', 'lat' => null, 'lng' => null, 'query' => '', 'label' => '', 'on_contact' => false],
        ];
    }

    // ---------------------------------------------------------------- single values

    /** Canonical https://m.me/<page> or null. Exact host, no port, no user info, no query, no fragment. */
    public static function messengerUrl(mixed $value): ?string
    {
        $u = self::cleanUrl($value);
        if ($u === null || $u['host'] !== 'm.me' || isset($u['query']) || isset($u['fragment'])) return null;
        if (!preg_match('#^/([A-Za-z0-9][A-Za-z0-9._-]{0,79})/?$#D', $u['path'] ?? '', $m)) return null;
        return 'https://m.me/'.$m[1];
    }

    /** Canonical https://line.me/R/ti/p/@id or https://lin.ee/<code>, or null. */
    public static function lineUrl(mixed $value): ?string
    {
        $u = self::cleanUrl($value);
        if ($u === null || isset($u['query']) || isset($u['fragment'])) return null;
        $path = $u['path'] ?? '';
        if ($u['host'] === 'line.me') {
            if (!preg_match('#^/(?:R/)?ti/p/(?:@|%40)([A-Za-z0-9][A-Za-z0-9._-]{0,39})/?$#D', $path, $m)) return null;
            return 'https://line.me/R/ti/p/@'.$m[1];
        }
        if ($u['host'] === 'lin.ee') {
            if (!preg_match('#^/([A-Za-z0-9]{3,32})/?$#D', $path, $m)) return null;
            return 'https://lin.ee/'.$m[1];
        }
        return null;
    }

    /** @return array{host:string,path?:string,query?:string,fragment?:string}|null an https URL with a bare host, nothing else */
    private static function cleanUrl(mixed $value): ?array
    {
        if (!is_string($value)) return null;
        $v = trim($value);
        if ($v === '' || strlen($v) > 200 || preg_match('/[\x00-\x20\x7f\\\\]/', $v)) return null;
        $p = parse_url($v);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || !isset($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['port'])) return null;
        $p['host'] = strtolower($p['host']);
        return $p;
    }

    public static function latitude(mixed $v): ?float
    {
        return self::coordinate($v, 90);
    }

    public static function longitude(mixed $v): ?float
    {
        return self::coordinate($v, 180);
    }

    private static function coordinate(mixed $v, int $max): ?float
    {
        if (is_int($v) || is_float($v)) $v = rtrim(rtrim(sprintf('%.8F', $v), '0'), '.');
        if (!is_string($v) || !preg_match('/^-?\d{1,3}(\.\d{1,8})?$/D', trim($v))) return null;
        $f = (float) $v;
        return abs($f) <= $max ? round($f, 6) : null;
    }

    /** A place to search for: letters, marks, digits, spaces and . , - / ( ) # only, 3 to 120 characters. */
    public static function placeQuery(mixed $v): ?string
    {
        if (!is_string($v)) return null;
        $s = trim((string) preg_replace('/\s+/u', ' ', $v));
        return preg_match('/^[\p{L}\p{M}\p{N} .,\/()#-]{3,120}$/u', $s) ? $s : null;
    }

    private static function label(mixed $v): ?string
    {
        if (!is_string($v)) return null;
        $s = trim((string) preg_replace('/\s+/u', ' ', $v));
        return mb_strlen($s) <= 80 && !preg_match('/[\x00-\x1f\x7f<>]/u', $s) ? $s : null;
    }

    // ---------------------------------------------------------------- whole value

    /** Lenient: whatever is not clean falls back to the default. Always returns the complete shape. */
    public static function normalize(mixed $in): array
    {
        $d = self::defaults();
        if (!is_array($in)) return $d;
        $p = is_array($in['popular'] ?? null) ? $in['popular'] : [];
        $d['popular']['days'] = in_array($p['days'] ?? null, self::DAYS, true) ? $p['days'] : 7;
        $d['popular']['limit'] = is_int($p['limit'] ?? null) && $p['limit'] >= 3 && $p['limit'] <= 10 ? $p['limit'] : 5;
        $d['show_views'] = ($in['show_views'] ?? false) === true;
        $c = is_array($in['chat'] ?? null) ? $in['chat'] : [];
        $d['chat']['messenger'] = self::messengerUrl($c['messenger'] ?? '') ?? '';
        $d['chat']['line'] = self::lineUrl($c['line'] ?? '') ?? '';
        $d['chat']['placement'] = in_array($c['placement'] ?? null, self::PLACEMENTS, true) ? $c['placement'] : 'floating';
        $m = is_array($in['map'] ?? null) ? $in['map'] : [];
        $d['map']['provider'] = array_key_exists((string) ($m['provider'] ?? ''), self::PROVIDERS) ? $m['provider'] : 'google';
        $lat = self::latitude($m['lat'] ?? null);
        $lng = self::longitude($m['lng'] ?? null);
        if ($lat !== null && $lng !== null) { $d['map']['lat'] = $lat; $d['map']['lng'] = $lng; }
        $d['map']['query'] = self::placeQuery($m['query'] ?? '') ?? '';
        $d['map']['label'] = self::label($m['label'] ?? '') ?? '';
        $d['map']['on_contact'] = ($m['on_contact'] ?? false) === true;
        return $d;
    }

    /** Strict (staff save): the first Thai problem, or null when every value is acceptable. */
    public static function problem(mixed $in): ?string
    {
        if (!is_array($in) || array_is_list($in) && $in !== []) return 'ข้อมูลส่วนเสริมหน้าเว็บไซต์ไม่ถูกต้อง';
        if (array_diff(array_keys($in), self::KEYS)) return 'ข้อมูลส่วนเสริมหน้าเว็บไซต์มีรายการที่ไม่รู้จัก';
        $sub = ['popular' => ['days', 'limit'], 'chat' => ['messenger', 'line', 'placement'], 'map' => ['provider', 'lat', 'lng', 'query', 'label', 'on_contact']];
        foreach ($sub as $k => $keys) {
            if (!isset($in[$k])) continue;
            if (!is_array($in[$k]) || array_diff(array_keys($in[$k]), $keys)) return 'ข้อมูลส่วนเสริมหน้าเว็บไซต์มีรายการที่ไม่รู้จัก';
        }
        if (isset($in['show_views']) && !is_bool($in['show_views'])) return 'การแสดงยอดอ่านต้องเป็นเปิดหรือปิด';
        $p = $in['popular'] ?? [];
        if (isset($p['days']) && !in_array($p['days'], self::DAYS, true)) return 'ช่วงวันของข่าวยอดนิยมเลือกได้ 7 หรือ 30 วัน';
        if (isset($p['limit']) && (!is_int($p['limit']) || $p['limit'] < 3 || $p['limit'] > 10)) return 'จำนวนข่าวยอดนิยมต้องอยู่ระหว่าง 3 ถึง 10 รายการ';
        $c = $in['chat'] ?? [];
        if (self::present($c['messenger'] ?? '') && self::messengerUrl($c['messenger']) === null) return 'ลิงก์ Messenger ต้องเป็น https://m.me/ตามด้วยชื่อเพจ (ตัวอักษร ตัวเลข จุด ขีด ขีดล่าง)';
        if (self::present($c['line'] ?? '') && self::lineUrl($c['line']) === null) return 'ลิงก์ LINE ต้องเป็น https://line.me/R/ti/p/@ไอดี หรือ https://lin.ee/รหัส';
        if (isset($c['placement']) && !in_array($c['placement'], self::PLACEMENTS, true)) return 'ตำแหน่งปุ่มแชตไม่ถูกต้อง';
        $m = $in['map'] ?? [];
        if (isset($m['provider']) && !array_key_exists((string) $m['provider'], self::PROVIDERS)) return 'ผู้ให้บริการแผนที่เลือกได้เฉพาะ Google Maps หรือ OpenStreetMap';
        $hasLat = self::present($m['lat'] ?? null);
        $hasLng = self::present($m['lng'] ?? null);
        if ($hasLat !== $hasLng) return 'พิกัดแผนที่ต้องกรอกทั้งละติจูดและลองจิจูด';
        if ($hasLat && self::latitude($m['lat']) === null) return 'ละติจูดต้องอยู่ระหว่าง -90 ถึง 90';
        if ($hasLng && self::longitude($m['lng']) === null) return 'ลองจิจูดต้องอยู่ระหว่าง -180 ถึง 180';
        if (self::present($m['query'] ?? '') && self::placeQuery($m['query']) === null) return 'ชื่อสถานที่ในแผนที่ใช้ได้เฉพาะตัวอักษร ตัวเลข วรรค และเครื่องหมาย . , - / ( ) # ยาว 3 ถึง 120 ตัวอักษร';
        if (isset($m['label']) && self::label($m['label']) === null) return 'หัวข้อบล็อกแผนที่ยาวได้ไม่เกิน 80 ตัวอักษร และห้ามมีเครื่องหมาย < >';
        if (isset($m['on_contact']) && !is_bool($m['on_contact'])) return 'การแสดงแผนที่ในหน้าติดต่อต้องเป็นเปิดหรือปิด';
        if (($m['provider'] ?? 'google') === 'osm' && !$hasLat && self::present($m['query'] ?? '')) return 'OpenStreetMap ต้องระบุพิกัด (ละติจูดและลองจิจูด) ใช้ชื่อสถานที่อย่างเดียวไม่ได้';
        return null;
    }

    private static function present(mixed $v): bool
    {
        return $v !== null && $v !== '' && $v !== [];
    }

    // ---------------------------------------------------------------- rendering helpers

    /** @return list<array{key:string,label:string,url:string,aria:string}> */
    public static function chatLinks(array $extras): array
    {
        $c = self::normalize($extras)['chat'];
        $out = [];
        if ($c['messenger'] !== '') $out[] = ['key' => 'messenger', 'label' => 'Messenger', 'url' => $c['messenger'], 'aria' => 'แชตกับโรงเรียนทาง Messenger (เปิดในแท็บใหม่)'];
        if ($c['line'] !== '') $out[] = ['key' => 'line', 'label' => 'LINE', 'url' => $c['line'], 'aria' => 'แชตกับโรงเรียนทาง LINE Official Account (เปิดในแท็บใหม่)'];
        return $out;
    }

    /**
     * What the map block prints, or null when there is nothing to show. Every URL is built here from validated numbers or an encoded query:
     * link = plain page of the provider (works without JS), embed = iframe address inserted only after a click, frame_source = the one CSP frame-src entry.
     * @return array{provider:string,provider_name:string,title:string,link:string,embed:string,frame_source:string,query:string,lat:?float,lng:?float}|null
     */
    public static function mapView(array $extras): ?array
    {
        $m = self::normalize($extras)['map'];
        $hasCoords = $m['lat'] !== null && $m['lng'] !== null;
        if (!$hasCoords && $m['query'] === '') return null;
        $f = fn (float $n): string => sprintf('%.6F', $n);
        if ($m['provider'] === 'osm') {
            if (!$hasCoords) return null;
            [$lat, $lng] = [$f($m['lat']), $f($m['lng'])];
            $bbox = implode(',', [$f($m['lng'] - 0.004), $f($m['lat'] - 0.003), $f($m['lng'] + 0.004), $f($m['lat'] + 0.003)]);
            $link = 'https://www.openstreetmap.org/?mlat='.$lat.'&mlon='.$lng.'#map=17/'.$lat.'/'.$lng;
            $embed = 'https://www.openstreetmap.org/export/embed.html?bbox='.rawurlencode($bbox).'&layer=mapnik&marker='.rawurlencode($lat.','.$lng);
        } else {
            $q = $hasCoords ? $f($m['lat']).','.$f($m['lng']) : $m['query'];
            $link = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($q);
            $embed = 'https://www.google.com/maps?q='.rawurlencode($q).'&z=16&output=embed';
        }
        return ['provider' => $m['provider'], 'provider_name' => self::PROVIDERS[$m['provider']], 'title' => $m['label'] !== '' ? $m['label'] : 'แผนที่โรงเรียน',
            'link' => $link, 'embed' => $embed, 'frame_source' => self::FRAME_SOURCES[$m['provider']], 'query' => $m['query'], 'lat' => $m['lat'], 'lng' => $m['lng']];
    }
}
