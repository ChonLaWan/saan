<x-account-layout title="อัปเดตระบบ">
<link rel="stylesheet" href="{{ \App\Support\WebsiteView::asset('assets/updates-admin.css') }}"><script defer src="{{ \App\Support\WebsiteView::asset('assets/updates-admin.js') }}"></script>
<p class="account-intro">ดูรุ่นที่ติดตั้ง ตรวจรุ่นใหม่ ดาวน์โหลดและติดตั้งได้จากหน้านี้ทั้งตัวระบบสาน โมดูล และรุ่นของ Laravel ที่มากับตัวระบบ ทุกแพ็กเกจมีลายเซ็นของโครงการ ระบบสำรองข้อมูลก่อนติดตั้งทุกครั้ง และคืนรุ่นเดิมให้เองถ้าไม่สำเร็จ</p>
<noscript><div class="account-alert error" role="alert">หน้านี้ต้องเปิดใช้ JavaScript จึงจะตรวจและติดตั้งรุ่นใหม่ได้ ถ้าใช้ไม่ได้ ติดตั้งด้วยคำสั่ง <code>php artisan saan:self-update</code> หรือ <code>php artisan saan:update &lt;ไฟล์&gt;</code></div></noscript>
<div class="account-alert upd-hidden" id="upd-auto-notice" role="status" hidden></div>

<section class="account-card glass upd-card" id="upd-installed" aria-labelledby="upd-h-installed"><h2 id="upd-h-installed">รุ่นที่ติดตั้ง</h2><div data-installed aria-busy="true"><p>กำลังโหลด…</p></div></section>

<section class="account-card glass upd-card" id="upd-new" aria-labelledby="upd-h-new"><h2 id="upd-h-new">รุ่นใหม่</h2>
<div data-new aria-busy="true"><p>กำลังโหลด…</p></div>
<p class="upd-error" data-new-error role="alert" hidden></p>
<div class="upd-actions"><button type="button" data-act="check">ตรวจรุ่นใหม่</button><button type="button" data-act="install" class="upd-primary" disabled>ดาวน์โหลดและติดตั้ง</button><button type="button" data-act="retry-auto" hidden>ให้ระบบลองอัปเดตอัตโนมัติอีกครั้ง</button></div>
</section>

<section class="account-card glass upd-card" id="upd-run" aria-labelledby="upd-h-run"><h2 id="upd-h-run">การทำงาน</h2>
<ol class="upd-steps" data-steps aria-label="ขั้นตอนการอัปเดต"></ol>
<p class="upd-progress-line" data-progress-line aria-live="polite">ยังไม่มีการอัปเดตที่กำลังทำงาน</p>
<div class="upd-bars" data-bars></div>
<pre class="upd-log" data-log role="log" aria-live="polite" hidden></pre>
</section>

<section class="account-card glass upd-card" id="upd-settings" aria-labelledby="upd-h-settings"><h2 id="upd-h-settings">การตั้งค่า</h2>
<form class="account-form upd-mode-form" data-mode-form>
<fieldset class="upd-fieldset"><legend>โหมดการอัปเดต</legend>
<label class="upd-radio"><input type="radio" name="mode" value="off"><span><b>ปิด</b><small>ไม่เชื่อมต่อภายนอกเลย ติดตั้งได้จากไฟล์ที่อัปโหลดเองเท่านั้น</small></span></label>
<label class="upd-radio"><input type="radio" name="mode" value="notify"><span><b>แจ้งเตือนรุ่นใหม่</b><small>วันละครั้งระบบดึงรายการรุ่นที่มีลายเซ็นจากเซิร์ฟเวอร์โครงการและแจ้งที่หน้านี้ ผู้ดูแลกดติดตั้งเอง</small></span></label>
<label class="upd-radio"><input type="radio" name="mode" value="auto_patch"><span><b>อัปเดตแพตช์อัตโนมัติ</b><small>แจ้งเตือนเหมือนโหมดก่อนหน้า และติดตั้งเองเฉพาะรุ่นแพตช์ (เลขสองตัวแรกเท่ากัน เช่น 0.7.0 เป็น 0.7.1) ในช่วงเวลากลางคืนที่ตั้งไว้ รุ่นละหนึ่งครั้ง ถ้าไม่สำเร็จจะไม่ลองซ้ำจนกว่าผู้ดูแลกดสั่ง รุ่นรองและรุ่นหลักไม่ติดตั้งเองเสมอ ต้องตั้ง cron เรียก <code>schedule:run</code> ทุกนาที</small></span></label>
</fieldset>
<fieldset class="upd-fieldset upd-window"><legend>ช่วงเวลาติดตั้งอัตโนมัติ (เวลาของระบบ)</legend>
<label>เริ่ม<input type="time" name="window_start" value="02:00" required></label><label>สิ้นสุด<input type="time" name="window_end" value="05:00" required></label>
<small class="account-help" data-window-hint>ค่าเริ่มต้น 02:00 ถึง 05:00 ใช้เฉพาะโหมดอัปเดตแพตช์อัตโนมัติ</small>
</fieldset>
<p class="upd-error" data-mode-error role="alert" hidden></p>
<button type="submit">บันทึกการตั้งค่า</button>
</form>
<p class="account-help upd-privacy">ความเป็นส่วนตัว: การตรวจรุ่นใหม่และการดาวน์โหลดเป็นคำขอ HTTPS ธรรมดาไปยังเว็บไซต์โครงการ (<span data-feed-host>saan.linuxthai.org</span>) ไม่ส่งชื่อโรงเรียน รหัสติดตั้ง หรือข้อมูลที่ระบุตัวตนใด ๆ ไปด้วย โฮสต์ต้องอนุญาตการเชื่อมต่อขาออกแบบ HTTPS ไปยังเว็บไซต์นี้</p>
</section>

<section class="account-card glass upd-card" id="upd-manual" aria-labelledby="upd-h-manual"><h2 id="upd-h-manual">ติดตั้งจากไฟล์ที่อัปโหลดเอง</h2>
<p class="account-help">ใช้เมื่อโฮสต์ออกอินเทอร์เน็ตไม่ได้ หรือเลือกโหมดปิด ดาวน์โหลดแพ็กเกจ <code>saan-core-*.zip</code> หรือ <code>saan-mod-*.zip</code> จาก <code>saan.linuxthai.org/download/</code> แล้ววางผ่าน File Manager ของโฮสต์ที่โฟลเดอร์ <code data-incoming>storage/app/private/updates/incoming</code> ระบบตรวจลายเซ็นให้ก่อนติดตั้ง</p>
<div data-manual aria-busy="true"><p>กำลังตรวจแพ็กเกจ…</p></div>
</section>

<section class="account-card glass upd-card" id="upd-history" aria-labelledby="upd-h-history"><h2 id="upd-h-history">ประวัติ</h2><div data-history aria-busy="true"><p>กำลังโหลด…</p></div></section>

<section class="account-card glass upd-card" id="upd-backups" aria-labelledby="upd-h-backups"><h2 id="upd-h-backups">ไฟล์สำรองก่อนอัปเดต</h2><div data-backups aria-busy="true"><p>กำลังโหลด…</p></div></section>

<dialog id="upd-dialog" class="upd-dialog" aria-labelledby="upd-dialog-title"><form method="dialog" id="upd-form"><h3 id="upd-dialog-title" data-title></h3><p data-text></p><label>รหัสผ่านของคุณ<input type="password" data-password autocomplete="current-password" maxlength="72" required></label><p class="upd-error" data-error role="alert" hidden></p><div class="upd-actions"><button type="button" data-cancel>ยกเลิก</button><button type="submit" data-ok class="upd-primary">ยืนยัน</button></div></form></dialog>
</x-account-layout>
