# Security Policy / นโยบายความปลอดภัย

## ไทย

### เวอร์ชันที่รองรับ
รองรับเฉพาะรุ่นล่าสุดที่เผยแพร่ที่ https://saan.linuxthai.org/download/ และซอร์สโค้ดรุ่นเดียวกันที่ https://saan.linuxthai.org/source/ หรือ https://github.com/ChonLaWan/saan ซึ่งยังเป็นรุ่นทดลอง (beta)
ไม่ใช่ระบบสารสนเทศนักเรียนที่สมบูรณ์ ก่อนใช้งานจริงต้องทดสอบบนเครื่องทดสอบและมีข้อมูลสำรองเสมอ

### วิธีแจ้งช่องโหว่
- ห้ามเปิด Issue สาธารณะสำหรับช่องโหว่
- แจ้งแบบส่วนตัวตามช่องทางที่ประกาศไว้ที่ https://saan.linuxthai.org/.well-known/security.txt (ไฟล์มาตรฐาน security.txt เป็นที่เดียวที่ระบุช่องทางติดต่อล่าสุด)
  หรือส่งอีเมลถึง webmaster@linuxthai.org โดยไม่ใส่รายละเอียดช่องโหว่ในข้อความแรก
- ระบุ: รุ่นที่ได้รับผลกระทบ ไฟล์และบรรทัด ขั้นตอนทำซ้ำโดยใช้ข้อมูลสมมติเท่านั้น ผลที่คาดหวัง และผลกระทบ
- ห้ามแนบรหัสผ่าน โทเคน ข้อมูลส่วนบุคคลจริง (นักเรียน ผู้ปกครอง บุคลากร) หรือไฟล์สำรองที่ไม่ได้ปกปิดข้อมูล
- การตอบ: ผู้ดูแลโครงการเป็นอาสาสมัคร จะรับทราบและแจ้งแนวทางแก้โดยเร็วที่สุดเท่าที่ทำได้ ยังไม่ให้คำมั่นเรื่องระยะเวลาตายตัว และเปิดเผยรายละเอียดหลังมีรุ่นแก้ไขให้ใช้งาน

### สำหรับผู้ติดตั้ง
- ใช้ HTTPS, `APP_DEBUG=false`, `SCHOOL_DEMO=false`, ตั้งค่า session แบบ secure
- DocumentRoot ชี้เฉพาะโฟลเดอร์ `public/` ห้ามเปิด `.env`, `storage`, `vendor` และไฟล์สำรองสู่เว็บ
- บัญชีฐานข้อมูลเข้าถึงได้เฉพาะฐานข้อมูลของระบบนี้
- ตัวติดตั้งมีโทเค็นเจ้าของนอก `public/` และล็อกตัวเองหลังติดตั้งเสร็จ
- รัน `composer audit --locked` ก่อนออกรุ่น ผลที่ผ่านมีผลเฉพาะเวลาที่ตรวจ ไม่ใช่หลักฐานว่าไม่มีช่องโหว่
- ข้อมูลนักเรียนและผู้ปกครองจริงอยู่ภายใต้ PDPA ห้ามนำไปไว้ใน repository, Issue หรือบันทึกข้อผิดพลาดสาธารณะ

## English

### Supported versions
Only the latest release published at https://saan.linuxthai.org/download/ (source: https://saan.linuxthai.org/source/ or https://github.com/ChonLaWan/saan) is supported. The project is beta software and not a
complete student-information system. Test on staging and keep backups before any production use.

### Reporting a vulnerability
- Do not open a public issue for a vulnerability.
- Report privately through the contact published at https://saan.linuxthai.org/.well-known/security.txt (the security.txt file is the single place that lists the current contact).
  Or write to webmaster@linuxthai.org and leave the vulnerability details out of the first message.
- Include the affected release, files and lines, reproduction using fictional markers only, expected behaviour and impact.
- Never attach credentials, tokens, real personal data (students, guardians, staff) or unredacted backups.
- Response: the maintainers are volunteers and answer as soon as they can; no fixed timeline is promised. Details are disclosed after a fixed release is available.

### For operators
- Use HTTPS, `APP_DEBUG=false`, `SCHOOL_DEMO=false` and secure session cookies.
- Point the DocumentRoot at `public/` only; never expose `.env`, `storage`, `vendor` or backups.
- Give the database account access to this application's database only.
- The installer is guarded by an ownership token stored outside `public/` and locks itself after completion.
- Run `composer audit --locked` before each release; a clean result is time-specific, not proof of safety.
- Real student and guardian records are personal data under Thai PDPA: keep them out of the repository, issues and public logs.
