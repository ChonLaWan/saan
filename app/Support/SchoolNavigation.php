<?php

namespace App\Support;

class SchoolNavigation
{
    public static function groups(string $role): array
    {
        $groups = [];
        // /development-plan and /website-coverage are internal development pages (resources/data/development-checklist.json): listed in demo installs only.
        $demo = (bool) config('school.demo');
        $opsOff = !app(\App\Support\Modules\ModuleRegistry::class)->active('operations');
        $item = function ($key, $label, $path, $icon, $status = 'local') use ($opsOff) {
            if ($opsOff && str_starts_with($path, '/operations')) $status = 'off';
            return compact('key', 'label', 'path', 'icon', 'status');
        };
        if (in_array($role, ['admin', 'principal', 'teacher'], true)) {
            $groups[] = ['label' => 'งานโรงเรียน', 'collapsible' => false, 'items' => [
                $item('work', 'งานโรงเรียน', '/work', 'layers'),
                ...array_map(fn($m)=>$item('work-'.$m['key'],$m['label'],$m['path'],$m['icon']),app(\App\Support\Modules\ModuleRegistry::class)->workMenu($role)),
            ]];
        }
        if (in_array($role, ['admin', 'principal', 'teacher'], true)) {
            $groups[] = ['label' => 'ภาพรวม (ดูอย่างเดียว)', 'collapsible' => false, 'items' => [
                $item('overview', 'ภาพรวมโรงเรียน', '/operations#overview', 'grid'),
                $item('timetable', 'ตารางสอน (ดูอย่างเดียว)', '/operations#timetable', 'calendar'),
                $item('duty', 'ตารางครูเวร (ดูอย่างเดียว)', '/operations#duty', 'shield'),
                $item('workload', 'ครูและภาระงาน', '/operations#workload', 'people'),
                $role === 'teacher'
                    ? $item('requests', 'คำขอลาของฉัน (ดูอย่างเดียว)', '/operations#people', 'check')
                    : $item('approvals', 'งานรออนุมัติ (ดูอย่างเดียว)', '/operations#approvals', 'check'),
                ...($demo ? [$item('plan', 'แผนระบบหลังบ้าน', '/development-plan', 'layers')] : []),
            ]];
        }
        if (in_array($role, ['admin', 'principal', 'editor'], true)) {
            $groups[] = ['label' => 'เว็บไซต์โรงเรียน', 'collapsible' => false, 'items' => [
                $item('cms-home', 'ภาพรวมเว็บไซต์', '/cms#home', 'web'),
                $item('compose', 'เขียนข่าวใหม่', '/cms#compose', 'edit'),
                $item('posts', 'ข่าวทั้งหมด', '/cms#posts', 'document'),
                $item('theme', 'ปรับธีมหน้าบ้าน', '/cms#theme', 'palette'),
                $item('branding', 'โลโก้และชื่อโรงเรียน', '/cms#branding', 'palette'),
                $item('header', 'ส่วนหัวและเมนู', '/cms#header', 'web'),
                $item('hero-design', 'ออกแบบ hero', '/cms#hero-design', 'web'),
                $item('slides', 'สไลด์หน้าแรก', '/cms#slides', 'web'),
                $item('observance', 'ไว้ทุกข์ / เฉลิมฉลอง', '/cms#observance', 'web'),
                ...($demo ? [$item('coverage', 'ตรวจหน้าบ้านและเมนู', '/website-coverage', 'check')] : []),
                ...(\App\Domain\Cms\DemoLibrary::available()?[$item('demo-library', 'คลังภาพและข้อมูลสมมติ', '/demo-library', 'grid')]:[]),
            ]];
            $groups[] = ['label' => 'เนื้อหาเว็บไซต์', 'collapsible' => true, 'items' => [
                $item('pages', 'หน้าเนื้อหาโรงเรียน', '/cms#pages', 'document'),
                $item('jobs', 'ประกาศรับสมัครงาน', '/cms#jobs', 'people'),
                $item('procurement', 'จัดซื้อจัดจ้าง', '/cms#procurement', 'document'),
                $item('albums', 'อัลบั้มภาพ', '/cms#albums', 'grid'),
                $item('downloads', 'เอกสารดาวน์โหลด', '/cms#downloads', 'document'),
                $item('videos', 'วิดีโอโรงเรียน', '/cms#videos', 'web'),
                $item('newsletters', 'จดหมายข่าว', '/cms#newsletters', 'document'),
                $item('website-people', 'บุคลากรบนเว็บไซต์', '/cms#people', 'people'),
                $item('events', 'ปฏิทินกิจกรรม', '/cms#events', 'calendar'),
                $item('banners', 'แบนเนอร์และประกาศด่วน', '/cms#banners', 'web'),
                $item('services', 'ลิงก์และบริการ', '/cms#services', 'layers'),
                ...array_map(fn($m)=>$item($m['key'],$m['label'],$m['path'],$m['icon']),app(\App\Support\Modules\ModuleRegistry::class)->adminMenu($role)),
            ]];
            $groups[] = ['label' => 'ตั้งค่าเว็บไซต์', 'collapsible' => true, 'items' => [
                $item('profile', 'ข้อมูลโรงเรียน', '/cms#profile', 'edit'),
                $item('identity', 'favicon และโหมดสี', '/cms#identity', 'palette'),
                ...($role==='admin'?[$item('mail','ตั้งค่าการส่งอีเมล','/cms#mail','web')]:[]),
                $item('redirects', 'Redirect URL เดิม', '/cms#redirects', 'layers'),
                $item('homepage', 'จัดหน้าแรก', '/cms#homepage', 'grid'),
                $item('menus', 'เมนูเว็บไซต์', '/cms#menus', 'layers'),
                $item('media', 'คลังสื่อและเอกสาร', '/cms#media', 'document'),
                $item('categories', 'หมวดเนื้อหา', '/cms#categories', 'layers'),
                $item('inbox', 'ข้อความติดต่อ', '/cms#inbox', 'document'),
                $item('audit', 'ประวัติการจัดการ', '/cms#audit', 'shield'),
                $item('trash', 'ถังขยะ', '/cms#trash', 'document'),
            ]];
        }
        if (in_array($role, ['admin', 'principal', 'teacher'], true)) {
            $groups[] = ['label' => 'นักเรียนและบุคลากร', 'collapsible' => true, 'items' => [
                $item('care', 'นักเรียนและเวลาเรียน (ดูอย่างเดียว)', '/operations#care', 'people'),
                $item('assessment', 'วัดผลและคะแนนตัวอย่าง (ดูอย่างเดียว)', '/operations#assessment', 'chart'),
                $item('people', 'ครูและการลา (ดูอย่างเดียว)', '/operations#people', 'calendar'),
            ]];
        }
        if ($demo) {
            $plan = json_decode(file_get_contents(resource_path('data/development-checklist.json')), true, 512, JSON_THROW_ON_ERROR);
            $groups[] = ['label' => 'ทุกระบบใน checklist', 'collapsible' => true, 'items' => array_map(
                fn ($module) => $item($module['id'], $module['id'].' '.$module['title'], '/development-plan#'.$module['id'], 'layers', 'plan'),
                array_merge($plan['modules'], $plan['foundations'])
            )];
        }
        $accounts = [$item('account', 'บัญชีของฉัน', '/account', 'person')];
        if ($role === 'admin') array_unshift($accounts, $item('users', 'จัดการผู้ใช้งาน', '/admin/users', 'people'),$item('deployment','ตรวจความพร้อมระบบ','/cms#deployment','shield'),$item('updates','อัปเดตระบบ','/admin/updates','download'));
        if ($role === 'editor' && $demo) array_unshift($accounts, $item('plan', 'แผนระบบหลังบ้าน', '/development-plan', 'layers'));
        $groups[] = ['label' => 'บัญชีและระบบ', 'collapsible' => false, 'items' => $accounts];
        return $groups;
    }
}
