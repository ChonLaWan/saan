<?php
namespace App\Support;

use App\Domain\Cms\ContentTypes;

class WebsiteSections
{
    public static function all(): array
    {
        $details=[
            'article'=>[0,'ข่าวประชาสัมพันธ์และกิจกรรมล่าสุด'],
            'page'=>[4,'ประวัติ วิสัยทัศน์ หลักสูตร และข้อมูลโรงเรียน'],
            'job'=>[7,'ตำแหน่งงาน คุณสมบัติ วันรับสมัคร และเอกสาร'],
            'procurement'=>[6,'ประกาศจัดซื้อจัดจ้างและเอกสารแนบ'],
            'album'=>[1,'ภาพกิจกรรมและบรรยากาศการเรียนรู้'],
            'download'=>[3,'แบบฟอร์ม คู่มือ และไฟล์ดาวน์โหลด'],
            'person'=>[4,'ผู้บริหาร ครู และบุคลากรที่เผยแพร่บนเว็บไซต์'],
            'event'=>[5,'วันเวลา สถานที่ และกิจกรรมของโรงเรียน'],
            'banner'=>[0,'สไลด์ ข่าวด่วน และประกาศบนหน้าแรก'],
            'service'=>[2,'ลิงก์บริการสำหรับนักเรียนและผู้ปกครอง'],
            'video'=>[1,'วิดีโอกิจกรรม เลือกเล่นก่อนเชื่อมผู้ให้บริการ'],
            'newsletter'=>[0,'จดหมายข่าวรายฉบับและเอกสารประกอบ'],
        ];
        $sections=[];
        foreach(ContentTypes::TYPES as $key=>$type){
            $sections[$key]=$type+['key'=>$key,'icon'=>$details[$key][0],'description'=>$details[$key][1],
                'admin_path'=>'/cms#'.($key==='article'?'posts':$type['route']),
                'public_path'=>'/portal/'.$type['route']];
        }
        return $sections;
    }
}
