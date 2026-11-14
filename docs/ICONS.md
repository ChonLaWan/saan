# ไอคอนลูกศรของระบบ

ใช้ SVG แบบเส้นในกรอบ `viewBox="0 0 24 24"` หนา `2` ปลายมน แสดงผล 20 × 20px และใช้ `currentColor` เพื่อรับสีจากธีม ไม่ใช้ตัวอักษรลูกศรหรือฟอนต์ไอคอนจาก CDN

- Blade: `<x-ui-icon name="arrow-right"/>`
- JavaScript template: `${window.SchoolIcons.render('arrow-right')}`
- ชุดชื่อ: `arrow-right`, `arrow-left`, `arrow-up-right`, `arrow-down`, `chevron-right`, `chevron-left`, `chevron-down`, `chevron-up`

ไฟล์ต้นฉบับคือ `public/assets/ui-icons.json` เมื่อเพิ่มหรือแก้ path ให้รัน `php tools/build-ui-icons.php` และเก็บ JavaScript ที่สร้างแล้วใน source ด้วย PHP อ่าน path เดียวกันผ่าน `App\Support\UiIcon` ส่วนหน้า JavaScript ใช้ชุดที่สร้างไว้ จึงไม่ต้อง fetch ไอคอนขณะเปิดหน้า และไม่ต้อง build assets ตอนติดตั้ง

โหลด CSS/JavaScript ผ่าน component `appearance-assets` หน้า preview ที่ปิด JavaScript ยังแสดง SVG จาก Blade ได้ ชื่อที่ไม่รู้จักใช้ `arrow-right` เป็น fallback

ไอคอนเป็นส่วนตกแต่ง: `aria-hidden="true"`, `focusable="false"` ปุ่มที่มีเฉพาะไอคอนต้องมี `aria-label` ที่ปุ่ม ลิงก์ควรคงข้อความไทยที่บอกปลายทาง

การ์ดต้องเหลือพื้นที่รอบลูกศรอย่างน้อย 8px ใช้ gutter 12–16px ตาม CSS ปัจจุบัน และตรวจขนาดไอคอน ระยะขอบ การตัดข้อความ รวมถึงโหมดสว่าง/มืด/ชัดสูง/มืดชัดก่อนแก้ layout เพิ่ม ห้ามให้กฎของ SVG รูปประกอบ เช่น margin หรือ flex shrink เปลี่ยนกล่องไอคอน
