<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        \App\Domain\Cms\ContentTypes::ensureCategories(1);
        DB::table('academic_terms')->insert(['id'=>1,'school_id'=>1,'year'=>2569,'term'=>1,'starts_on'=>'2026-05-01','ends_on'=>'2027-03-31']);
        foreach(['ครูอรุณ','ครูริน','ครูภูมิ','ครูขวัญ','ครูวาด','ครูเมธา'] as $i=>$name) DB::table('teachers')->insert(['id'=>$i+1,'school_id'=>1,'name'=>$name.' (สมมติ)','subject'=>['คณิตศาสตร์','ภาษาไทย','วิทยาศาสตร์','ภาษาอังกฤษ','ศิลปะ','พลศึกษา'][$i]]);
        foreach(['ป.4/1','ป.5/1','ม.1/1'] as $i=>$name) {DB::table('classrooms')->insert(['id'=>$i+1,'school_id'=>1,'name'=>$name,'homeroom_teacher_id'=>$i+1]);for($j=1;$j<=8;$j++) DB::table('students')->insert(['school_id'=>1,'classroom_id'=>$i+1,'code'=>sprintf('DEMO-%02d',$i*8+$j),'name'=>sprintf('นักเรียนตัวอย่าง %02d',$i*8+$j)]);}
        foreach([['08:30','09:20'],['09:20','10:10'],['10:20','11:10'],['11:10','12:00'],['13:00','13:50'],['13:50','14:40'],['14:40','15:30']] as $i=>$times) DB::table('bell_periods')->insert(['id'=>$i+1,'school_id'=>1,'number'=>$i+1,'starts_at'=>$times[0],'ends_at'=>$times[1]]);
        foreach([['admin','ผู้ดูแลโรงเรียน',null,null],['teacher','ครูอรุณ',1,1],['principal','ผู้อำนวยการสาธิต',6,null]] as $i=>$u) DB::table('users')->insert(['id'=>$i+1,'school_id'=>1,'name'=>$u[1].' (สมมติ)','email'=>$u[0].'@demo.school.test','password'=>Hash::make(bin2hex(random_bytes(24))),'role'=>$u[0],'is_demo'=>true,'teacher_id'=>$u[2],'classroom_id'=>$u[3],'created_at'=>now(),'updated_at'=>now()]);
        $subjects=['คณิตศาสตร์','ภาษาไทย','วิทยาศาสตร์','ภาษาอังกฤษ','ศิลปะ','พลศึกษา'];
        for($d=1;$d<=5;$d++) for($p=1;$p<=7;$p++) for($c=1;$c<=3;$c++) {
            if(($d+$p+$c)%4===0)continue;
            $teacher=(($p+$d+$c-3)%6)+1;
            DB::table('lessons')->insert(['school_id'=>1,'term_id'=>1,'weekday'=>$d,'period_id'=>$p,'teacher_id'=>$teacher,'classroom_id'=>$c,'room'=>'ห้อง '.$c,'subject'=>$subjects[$teacher-1],'created_at'=>now(),'updated_at'=>now()]);
        }
        foreach([['2026-11-05',2,'08:00','08:20','ประตูโรงเรียน'],['2026-11-05',3,'12:00','12:40','โรงอาหาร'],['2026-11-06',1,'08:00','08:20','ประตูโรงเรียน'],['2026-11-06',5,'15:40','16:10','ทางข้าม']] as $d) DB::table('duties')->insert(['school_id'=>1,'date'=>$d[0],'teacher_id'=>$d[1],'starts_at'=>$d[2],'ends_at'=>$d[3],'location'=>$d[4],'created_at'=>now(),'updated_at'=>now()]);
        DB::table('leave_requests')->insert(['school_id'=>1,'teacher_id'=>1,'starts_on'=>'2026-11-06','ends_on'=>'2026-11-06','type'=>'personal','reason'=>'ธุระส่วนตัว (ข้อมูลสาธิต)','created_at'=>now(),'updated_at'=>now()]);
        DB::table('leave_requests')->insert(['school_id'=>1,'teacher_id'=>4,'starts_on'=>'2026-11-09','ends_on'=>'2026-11-09','type'=>'personal','reason'=>'คำขอทดสอบการอนุมัติ','created_at'=>now(),'updated_at'=>now()]);
        foreach(range(1,24) as $id) DB::table('attendances')->insert(['school_id'=>1,'student_id'=>$id,'date'=>'2026-11-05','status'=>$id===5?'absent':($id===12?'late':'present'),'recorded_by'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $this->complaints();
        DB::table('posts')->insert(['school_id'=>1,'slug'=>'news-1','title'=>'พื้นที่ทดลองสำหรับโรงเรียนไทย','body'=>'ข่าวตัวอย่างเพื่อทดสอบการเผยแพร่ ไม่มีข้อมูลนักเรียนจริง','status'=>'published','author_id'=>1,'published_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
    }

    /**
     * Demo complaints of the invented school "โรงเรียนต้นธารวิทยา" (F1). Everything here is made up. Status page of the demo: receipt C69-DEM2K7XQ with the
     * tracking code TNTHAN4ME6QP shows a replied case. The corruption case is invisible to everybody until a demo executive is set in the module settings.
     */
    private function complaints(): void
    {
        if (!class_exists(\Saan\Modules\Complaints\Complaints::class) || !\Illuminate\Support\Facades\Schema::hasTable('cmp_cases')) return;
        $C = \Saan\Modules\Complaints\Complaints::class;
        $contact = fn (string $name, string $email) => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['name' => $name, 'email' => $email, 'phone' => ''], JSON_UNESCAPED_UNICODE));
        $rows = [
            ['C69-DEM2K7XQ', 'TNTHAN4ME6QP', 'general', false, $contact('ผู้ปกครองตัวอย่าง (สมมติ)', 'parent@demo.school.test'), 'สอบถามเรื่องจุดรับส่งนักเรียนหน้าโรงเรียนต้นธารวิทยา', 'ช่วงเย็นรถจอดแออัดบริเวณประตูหลัง ขอให้โรงเรียนพิจารณาจัดจุดรับส่งใหม่ (ข้อมูลสาธิต)', 'replied', 12, 'โรงเรียนจะปรับจุดรับส่งตั้งแต่ภาคเรียนถัดไป และประกาศให้ผู้ปกครองทราบ (ข้อความสาธิต)'],
            ['C69-DEM3P8WR', 'DEMQST3CDEXY', 'suggestion', true, null, 'ข้อเสนอแนะเรื่องมุมอ่านหนังสือ', 'อยากให้ห้องสมุดเปิดช่วงพักกลางวันเพิ่มขึ้น (ข้อมูลสาธิต)', 'in_progress', 3, null],
            ['C69-DEM4N5VT', 'DEMQST4CDEXY', 'info_request', false, $contact('ผู้ขอข้อมูลตัวอย่าง (สมมติ)', 'info@demo.school.test'), 'ขอสำเนาแผนปฏิบัติการประจำปี', 'ขอข้อมูลแผนปฏิบัติการประจำปีงบประมาณปัจจุบันของโรงเรียนต้นธารวิทยา (ข้อมูลสาธิต)', 'received', 1, null],
            ['C69-DEM5M6UK', 'DEMQST5CDEXY', 'corruption', true, null, 'เรื่องตัวอย่างสำหรับทดสอบสิทธิ์การเห็นเรื่องทุจริต', 'ข้อความสมมติสำหรับทดสอบการซ่อนเรื่องจากผู้ที่ไม่ใช่ผู้บริหารที่กำหนด ไม่มีเหตุการณ์จริง', 'received', 2, null],
        ];
        foreach ($rows as [$receipt, $code, $category, $anon, $contactJson, $subject, $detail, $status, $daysAgo, $reply]) {
            $at = now()->subDays($daysAgo);
            DB::table('cmp_cases')->insert(['school_id' => 1, 'receipt_no' => $receipt, 'track_hash' => $C::trackHash($receipt, $code), 'envelope_hash' => null, 'category' => $category, 'anonymous' => $anon,
                'contact' => $contactJson, 'subject' => $subject, 'detail' => $detail, 'status' => $status, 'due_on' => $at->copy()->addDays($C::SLA_DEFAULT[$category])->toDateString(),
                'received_at' => $at, 'status_changed_at' => $at, 'public_reply' => $reply, 'public_reply_at' => $reply ? $at : null, 'version' => 1, 'created_at' => $at, 'updated_at' => $at]);
        }
    }
}
