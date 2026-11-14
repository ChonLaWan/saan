<?php
return [
    'required'=>'กรุณาระบุ :attribute','present'=>'กรุณาส่งข้อมูล :attribute','accepted'=>'กรุณายอมรับ :attribute',
    'string'=>':attribute ต้องเป็นข้อความ','integer'=>':attribute ต้องเป็นจำนวนเต็ม','numeric'=>':attribute ต้องเป็นตัวเลข','boolean'=>':attribute ไม่ถูกต้อง',
    'array'=>':attribute มีรูปแบบไม่ถูกต้อง','email'=>'กรุณาตรวจสอบรูปแบบ :attribute','url'=>'กรุณาตรวจสอบลิงก์ :attribute',
    'date'=>':attribute ต้องเป็นวันที่ที่ถูกต้อง','date_format'=>':attribute มีรูปแบบวันเวลาไม่ถูกต้อง','after_or_equal'=>':attribute ต้องไม่อยู่ก่อน :date',
    'in'=>':attribute ไม่อยู่ในตัวเลือกที่รองรับ','exists'=>'ไม่พบ :attribute ในโรงเรียนนี้','unique'=>':attribute นี้ถูกใช้แล้ว','distinct'=>':attribute มีรายการซ้ำ',
    'regex'=>':attribute มีรูปแบบไม่ถูกต้อง','file'=>'กรุณาเลือกไฟล์ :attribute','image'=>':attribute ต้องเป็นรูปภาพ',
    'mimes'=>':attribute ต้องเป็นไฟล์ชนิด :values','dimensions'=>'ขนาดภาพ :attribute เกินกำหนด','uploaded'=>'อัปโหลด :attribute ไม่สำเร็จ กรุณาตรวจขนาดไฟล์',
    'confirmed'=>'ยืนยัน :attribute ไม่ตรงกัน','current_password'=>'รหัสผ่านปัจจุบันไม่ถูกต้อง','required_if'=>'กรุณาระบุ :attribute เมื่อ :other เป็น :value',
    'min'=>['string'=>':attribute ต้องมีอย่างน้อย :min ตัวอักษร','numeric'=>':attribute ต้องไม่น้อยกว่า :min','array'=>':attribute ต้องมีอย่างน้อย :min รายการ','file'=>':attribute ต้องมีขนาดอย่างน้อย :min KB'],
    'max'=>['string'=>':attribute ต้องไม่เกิน :max ตัวอักษร','numeric'=>':attribute ต้องไม่เกิน :max','array'=>':attribute ต้องไม่เกิน :max รายการ','file'=>':attribute ต้องไม่เกิน :max KB'],
    'between'=>['numeric'=>':attribute ต้องอยู่ระหว่าง :min ถึง :max','string'=>':attribute ต้องมี :min–:max ตัวอักษร','array'=>':attribute ต้องมี :min–:max รายการ','file'=>':attribute ต้องมีขนาด :min–:max KB'],
    'attributes'=>['title'=>'หัวข้อ','body'=>'เนื้อหา','excerpt'=>'คำโปรย','content_type'=>'ประเภทเนื้อหา','category'=>'หมวดเนื้อหา','cover_media_id'=>'รูปปก','file'=>'ไฟล์','alt'=>'คำอธิบายภาพ','folder'=>'โฟลเดอร์','slug'=>'รหัส URL','version'=>'รุ่นข้อมูล','intent'=>'การบันทึก','scheduled_at'=>'เวลาเผยแพร่','email'=>'อีเมล','name'=>'ชื่อ','subject'=>'เรื่อง','message'=>'ข้อความ','password'=>'รหัสผ่าน','current_password'=>'รหัสผ่านปัจจุบัน','metadata.source_url'=>'ลิงก์ต้นฉบับ','metadata.source_school'=>'โรงเรียนผู้ออกประกาศ','metadata.deadline'=>'วันสิ้นสุด','metadata.ends_at'=>'เวลาสิ้นสุดกิจกรรม','metadata.starts_at'=>'เวลาเริ่มกิจกรรม','settings.navigation'=>'เมนูเว็บไซต์','settings.homepage_sections'=>'ส่วนประกอบหน้าแรก','settings.intro_heading'=>'ข้อความแนะนำ','settings.email'=>'อีเมลโรงเรียน'],
];
