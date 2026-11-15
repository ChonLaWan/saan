<?php
namespace App\Support;

class WebsiteCoverage
{
    public static function controls(): array
    {
        return [
            'cms-home'=>['ภาพรวมเว็บไซต์','/cms#home','/portal','ภาพรวมและทางลัดไปเว็บไซต์ ไม่เผยแพร่สถิติหลังบ้าน'],
            'compose'=>['เขียนข่าวใหม่','/cms#compose','/portal/news','ร่างไม่แสดงจนเผยแพร่ มีหน้า preview ที่ต้องเข้าสู่ระบบ'],
            'theme'=>['ธีม สี และตัวอักษร','/cms#theme','/portal','หน้าบ้านอ่านชุดที่เผยแพร่ ตัวอย่างร่างอยู่ /theme-preview'],
            'slides'=>['สไลด์หน้าแรก','/cms#slides','/portal#home','ภาพคอม/มือถือ ข้อความ ปุ่ม ลำดับ จุดโฟกัส วันเวลา และหยุดเล่น'],
            'branding'=>['โลโก้และชื่อโรงเรียน','/cms#branding','/portal','โลโก้หลัก/มืด/มือถือ ขนาด และตราเวกเตอร์'],
            'header'=>['ส่วนหัวและเมนู','/cms#header','/portal','โครงสร้าง header แถบบน ตัวอักษร สี และปุ่มพิเศษ'],
            'hero-design'=>['ออกแบบ hero','/cms#hero-design','/portal','ความสูง ตัวอักษร ปุ่ม เครื่องมือ และการเล่นสไลด์'],
            'observance'=>['ไว้ทุกข์ / เฉลิมฉลอง','/cms#observance','/portal','แสดงเฉพาะโหมดที่เปิดและอยู่ในช่วงเวลา แถบไว้อาลัยมุมจอ (ดูตัวอย่างก่อนบันทึกได้) และประกาศแบบหน้าต่างที่ปิดได้ด้วยปุ่ม/Escape'],
            'profile'=>['ข้อมูลโรงเรียน','/cms#profile','/portal#contact','ชื่อ คำขวัญ ระดับชั้น พันธกิจ สถิติ ที่อยู่ โทร อีเมล เวลา และ social'],
            'mail'=>['ตั้งค่าการส่งอีเมล','/cms#mail',null,'เฉพาะผู้ดูแล ไม่เผยแพร่ SMTP credentials ต่อสาธารณะ'],
            'social'=>['ข่าวจากเพจ Facebook','/cms#social','/portal','โมดูล web-social บล็อกหน้าแรกแบบกดเพื่อโหลด ไม่มี access token'],
            'ita'=>['ITA เปิดเผยข้อมูลสาธารณะ','/cms#ita','/portal/ita','โมดูล web-ita แสดงเฉพาะปีงบประมาณที่เผยแพร่ ไฟล์แนบเปิดได้เมื่อปีนั้นเผยแพร่'],
            // Module pages whose public side is per-item only (no index page to link): 5th element forces the kind.
            'surveys'=>['แบบสอบถามและเกียรติบัตร','/cms#surveys',null,'โมดูล surveys ผู้ตอบเปิดผ่านลิงก์รายชุด /portal/surveys/<slug> ไม่มีหน้ารวม ผลตอบ รายชื่อ และเกียรติบัตรจัดการหลังบ้าน','public_connected'],
            'notices'=>['ประกาศรายชื่อ','/cms#notices',null,'โมดูล web-notices ประกาศรายชุดที่ /portal/notices/<slug> ไม่มีหน้ารวม หน้าไม่ถูกจัดทำดัชนีค้นหา','public_connected'],
            'identity'=>['favicon และโหมดสี','/cms#identity','/portal','ไอคอนแท็บและโหมดสว่าง/มืด/ตามระบบ ไม่ใช่หน้าเนื้อหาแยก'],
            'homepage'=>['จัดหน้าแรก','/cms#homepage','/portal','เพิ่มบล็อกหน้าเนื้อหา เรียง/ซ่อนส่วนต่าง ๆ โดยคงลำดับที่ตั้งไว้'],
            'menus'=>['เมนูเว็บไซต์','/cms#menus','/portal','header/footer/utility สามระดับ เรียงกลุ่มและตรวจลิงก์ภายใน'],
            'media'=>['คลังสื่อและเอกสาร','/cms#media','/portal/downloads','ภาพ/ไฟล์เผยเฉพาะที่ถูกอ้างในฉบับเผยแพร่ ร่างและไฟล์ระบุตัวเด็กไม่ใช่ข้อมูลสาธารณะ'],
            'categories'=>['หมวดเนื้อหาและแท็ก','/cms#categories','/portal/news','ตัวกรองหมวด ป้ายหมวด และลิงก์แท็กในหน้าอ่าน'],
            'redirects'=>['Redirect URL เดิม','/cms#redirects',null,'ตอบ 301/302 ณ URL ที่ตั้งไว้ ไม่มีหน้า public สำหรับแก้ redirect'],
            'inbox'=>['ข้อความติดต่อ','/cms#inbox','/portal/contact','หน้าบ้านเป็นแบบฟอร์มส่งข้อความ รายชื่อและรายละเอียดใน inbox เป็นหลังบ้าน'],
            'audit'=>['ประวัติการจัดการ','/cms#audit',null,'เฉพาะเจ้าหน้าที่ CMS ไม่เปิดชื่อผู้ใช้หรือ audit log สู่สาธารณะ'],
            'trash'=>['ถังขยะและ revision','/cms#trash',null,'คืนเป็นร่าง/เปรียบเทียบใน editor ฉบับถังขยะไม่อยู่หน้าบ้าน'],
            'deployment'=>['ตรวจความพร้อมระบบ','/cms#deployment',null,'เฉพาะ admin แสดงสถานะเทคนิค ไม่ใช่การรับรอง production'],
            'users'=>['จัดการผู้ใช้งาน','/admin/users',null,'เฉพาะ admin บัญชี/สิทธิ์/session ไม่มีรายชื่อสาธารณะ'],
            'updates'=>['อัปเดตระบบ','/admin/updates',null,'เฉพาะ admin ตรวจรุ่นใหม่ ดาวน์โหลดและติดตั้งแพ็กเกจที่ลงลายเซ็น ไม่มีหน้าสาธารณะ'],
            'account'=>['บัญชีของฉัน','/account',null,'เจ้าของบัญชีแก้รหัสผ่านของตนเอง หน้าส่วนตัวต้องเข้าสู่ระบบ'],
            'coverage'=>['ตรวจหน้าบ้านและเมนู','/website-coverage',null,'หน้าจับคู่ระบบและลิงก์นี้สำหรับเจ้าหน้าที่ CMS'],
            'plan'=>['แผนระบบหลังบ้าน','/development-plan',null,'Checklist และลิงก์แบบระบบ ไม่ใช่แบบฟอร์มบันทึกของทุกโมดูล'],
        ];
    }

    public static function navigation(): array
    {
        $controls=self::controls();$byPath=[];
        if(\App\Domain\Cms\DemoLibrary::available())$controls['demo-library']=['คลังภาพและข้อมูลสมมติ','/demo-library',null,'คลังเฉพาะเจ้าหน้าที่ใน local demo ไม่ใช่ frontend รับข้อมูลของทุกโมดูล'];
        foreach($controls as $key=>$entry)$byPath[$entry[1]]=['kind'=>$entry[4]??($entry[2]?'public_connected':'staff_only'),'public_path'=>$entry[2],'note'=>$entry[3]];
        foreach(WebsiteSections::all() as $entry)$byPath[$entry['admin_path']]=['kind'=>'public_connected','public_path'=>$entry['public_path'],'note'=>'มีหน้ารวมและหน้าอ่านตามประเภท แสดงเฉพาะฉบับเผยแพร่'];
        $operational=[
            '/operations#overview'=>'ภาพรวมตัวอย่างงานโรงเรียน ไม่ใช่สรุปครบทุกโมดูล',
            '/operations#timetable'=>'หน้าจัดคาบตัวอย่างและตรวจชน ยังไม่ครบ workflow จัดตารางตาม checklist',
            '/operations#duty'=>'หน้าเวรตัวอย่างและตรวจครูว่าง ไม่ใช่ solver/approval เต็มระบบ',
            '/operations#workload'=>'ภาระสอน/เวรจากข้อมูลครูตัวอย่าง ยังไม่ใช่ทะเบียนบุคลากรเต็มระบบ',
            '/operations#approvals'=>'คิวอนุมัติการลาตัวอย่าง การอนุมัติตารางสอน/เวรยังเป็นแผน',
            '/operations#care'=>'มีตัวอย่างบันทึกการมาเรียน ยังไม่มีทะเบียนและคลังเอกสารเด็กจริง',
            '/operations#assessment'=>'ตัวอย่างคะแนน 70:30 ยังไม่ใช่วัดผล/ปพ. เต็มระบบ',
            '/operations#people'=>'ตัวอย่างครูและการลา ยังไม่ครบงานบุคลากรและนโยบายลาทุกแบบ',
        ];
        $rows=[];
        foreach(SchoolNavigation::groups('admin') as $group)foreach($group['items'] as $item){
            $info=$byPath[$item['path']]??null;
            if(isset($operational[$item['path']]))$info=['kind'=>'partial','public_path'=>null,'note'=>$operational[$item['path']]];
            if(str_starts_with($item['path'],'/work'))$info=['kind'=>'staff_only','public_path'=>null,'note'=>'หน้างานเจ้าหน้าที่ของโมดูล ต้องเข้าสู่ระบบ ไม่มีหน้า public'];
            if($item['status']==='plan')$info=['kind'=>'plan','public_path'=>null,'note'=>'หน้า checklist ระบุขอบเขตและสถานะ ไม่ใช่ frontend รับข้อมูลจริง'];
            $rows[]=$item+['group'=>$group['label']]+($info??['kind'=>'unknown','public_path'=>null,'note'=>'ยังไม่มี mapping ต้องตรวจเพิ่ม']);
        }
        return $rows;
    }

    public static function modules(): array
    {
        $plan=json_decode(file_get_contents(resource_path('data/development-checklist.json')),true,512,JSON_THROW_ON_ERROR);
        $examples=['M01'=>['/cms','CMS เว็บไซต์ 12 ประเภทมี UI และหน้าบ้าน ยังเหลืองาน deployment และโรงเรียน UAT'],
            'M02'=>['/account','มีบัญชี/ผู้ใช้/สิทธิ์พื้นฐาน ยังขาด invitations/mail reset/MFA และ session UI'],
            'M04'=>['/operations#care','มีตัวอย่างการมาเรียน ยังไม่ครบโมดูลเวลาเรียน'],
            'M07'=>['/operations#timetable','มีตารางและตรวจชนตัวอย่าง ยังไม่ครบการจัด/อนุมัติตารางเต็มระบบ'],
            'M09'=>['/operations#assessment','มีคะแนนตัวอย่าง 70:30 ยังไม่ใช่สมุดคะแนน/รายงานทางการ'],
            'M13'=>['/operations#workload','มีภาระงานจากครูตัวอย่าง ยังไม่มีทะเบียนบุคลากรเต็มระบบ'],
            'M14'=>['/operations#people','มีตัวอย่างยื่น/อนุมัติการลา ยังไม่ครบทุกนโยบายโรงเรียน'],
            'M24'=>['/operations#duty','มีเวรและข้อเสนอครูว่างตัวอย่าง ระบบช่วยจัดขั้นสูงยังเป็นแผน']];
        return array_map(function($module)use($examples){
            $example=$examples[$module['id']]??null;
            return ['id'=>$module['id'],'title'=>$module['title'],'kind'=>$example?'partial':($module['id']==='M03'?'design':'plan'),
                'path'=>$example[0]??($module['design_url']??'/development-plan#'.$module['id']),
                'note'=>$example[1]??($module['design_status']??'มี requirement/checklist ยังไม่มี frontend และ backend ใช้งานจริงของโมดูลเต็ม')];
        },$plan['modules']);
    }
}
