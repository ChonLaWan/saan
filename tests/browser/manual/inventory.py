#!/usr/bin/env python3
"""Builds docs/manual/inventory.json: one entry per module (modules/*/module.json) with its menu entries, routes (counts by
method, from `artisan route:list --json` of the audit install), assets, roles, and a first-look list of workflows to write.

Usage: python -I tests/browser/manual/inventory.py [--install D:\\AI-Project\\School\\install-trials\\audit-070] [--routes routes.json]
The route list is produced with:  C:\\APM\\php\\php.exe -c C:\\APM\\php\\php.ini artisan route:list --json   (run in the install folder)
Routes are attributed to a module when their controller class is in the module's PHP namespace (Saan\\Modules\\<Name>), else when
their URI matches a pattern of the module.json "routes" list (longest pattern wins); everything else is "core".
"""
import argparse
import json
import os
import re
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', line_buffering=True)
import manuallib as ML  # noqa: E402

PHP = r'C:\APM\php\php.exe'
PHP_INI = r'C:\APM\php\php.ini'

# First-look list of workflows (titles only) per module; to be refined by the worker who owns the module.
WORKFLOWS = {
    'academic': ['ตั้งปีการศึกษาและภาคเรียน', 'เพิ่มห้องเรียนและกำหนดครูประจำชั้น', 'ตั้งคาบเวลาและปฏิทินโรงเรียน (วันหยุด)', 'เพิ่มครูและมอบหมายวิชา'],
    'activities': ['เปิดชุมนุมและให้นักเรียนลงทะเบียน', 'เปิดและดำเนินการเลือกตั้งสภานักเรียน', 'ออกเกียรติบัตรกิจกรรม', 'ออก PIN เข้าแอปนักเรียน'],
    'api-gateway': ['สร้างคีย์ API สำหรับแอปภายนอก', 'เรียกใช้ API ด้วยคีย์ และเพิกถอนคีย์'],
    'assessment': ['สร้างรายวิชาและหลักสูตร', 'กรอกคะแนนรายวิชาของห้อง', 'ปิดคะแนนและตัดเกรด', 'ครูผู้สอนเห็นเฉพาะรายวิชาที่ตนสอน'],
    'attendance': ['(นำร่องแล้ว) เช็คชื่อ แก้ไข รายงานรายเดือน/รายวัน/รายบุคคล ส่งออก CSV'],
    'behavior': ['บันทึกความดีและความควรปรับปรุงของนักเรียน', 'ดูสรุปคะแนนพฤติกรรมของห้อง', 'ผู้ปกครองและนักเรียนเห็นผลในพอร์ทัล'],
    'complaints': ['ผู้ใช้ทั่วไปส่งเรื่องร้องเรียนจากหน้าเว็บ', 'เจ้าหน้าที่รับเรื่องและตอบกลับ', 'ผู้ร้องติดตามเรื่องด้วยเลขรับเรื่อง', 'ผู้อำนวยการเห็นเรื่องร้องเรียนทุจริต'],
    'connectors': ['นำเข้าข้อมูลปีเก่าจากไฟล์', 'ตรวจผลนำเข้าและย้อนกลับ'],
    'constructionism': ['สร้างโครงงาน', 'บันทึกความก้าวหน้าและแนบผลงาน', 'ครูให้ข้อสังเกตและประเมิน'],
    'demo-library': ['เปิดคลังภาพและข้อมูลสมมติ', 'นำภาพจากคลังไปใช้กับเนื้อหาเว็บไซต์'],
    'documents': ['ลงทะเบียนหนังสือรับ', 'ลงทะเบียนหนังสือส่ง', 'ค้นหาและแนบไฟล์หนังสือ', 'เสนอและสั่งการหนังสือ'],
    'exam-checker': ['สร้างชุดข้อสอบและเฉลย', 'ตรวจกระดาษคำตอบและบันทึกคะแนน', 'วิเคราะห์ข้อสอบ'],
    'finance': ['ออกใบแจ้งค่าธรรมเนียมให้ห้องหรือนักเรียน', 'รับชำระและออกใบเสร็จ', 'ผู้ปกครองแจ้งชำระพร้อมสลิป', 'ตรวจสลิปและยืนยัน', 'รายงานการเงินและค้างชำระ'],
    'guardian-portal': ['ครูออกลิงก์หรือบัญชีให้ผู้ปกครอง', 'ผู้ปกครองเข้าระบบและดูข้อมูลบุตรหลานทุกแท็บ', 'ผู้ปกครองรับทราบและส่งความเห็น', 'ลืมรหัสผ่าน'],
    'health': ['บันทึกน้ำหนักส่วนสูงของห้อง', 'ดูกราฟและสรุปภาวะโภชนาการ', 'ผู้ปกครองดูข้อมูลสุขภาพ'],
    'learning-links': ['ครูเพิ่มลิงก์การเรียนรู้ของห้อง', 'ออกรหัสเปิดหน้าลิงก์ของห้อง', 'นักเรียนเปิดหน้าลิงก์ด้วยรหัสห้อง'],
    'leave-duty': ['ครูยื่นใบลา', 'ผู้อำนวยการอนุมัติหรือไม่อนุมัติ', 'จัดเวรครูและแลกเวร', 'ดูปฏิทินเวรของตนเอง'],
    'maintenance': ['ครูแจ้งซ่อม', 'ช่างรับงานและปิดงานซ่อม', 'ลงทะเบียนครุภัณฑ์', 'ตรวจสอบประวัติการซ่อมของครุภัณฑ์'],
    'operations': ['เปิดภาพรวมโรงเรียนแบบดูอย่างเดียว', 'ตรวจว่าแต่ละหน้าดูอย่างเดียวจริง (ไม่มีปุ่มแก้ไข)'],
    'payslip': ['เจ้าหน้าที่สร้างงวดเงินเดือนและนำเข้าสลิป', 'ครูดูสลิปของตนเอง', 'ออกหนังสือรับรอง 50 ทวิ', 'ครูอื่นดูสลิปของคนอื่นไม่ได้'],
    'personnel': ['กรอกทะเบียนบุคลากรและแฟ้มสะสมงาน', 'เผยแพร่รายชื่อบุคลากรบนเว็บไซต์', 'แนบเอกสารแฟ้มสะสมงาน'],
    'projects': ['เสนอโครงการและแผนปฏิบัติการ', 'ผู้อำนวยการอนุมัติโครงการ', 'ติดตามงบประมาณและรายงานผล'],
    'registry': ['เพิ่มนักเรียนใหม่และผูกผู้ปกครอง', 'แก้ข้อมูลและย้ายห้อง', 'นำเข้านักเรียนจากไฟล์ CSV', 'ส่งออกทะเบียน', 'จำหน่ายนักเรียน'],
    'reports-pp': ['พิมพ์ ปพ.5 / ปพ.6 ของห้อง', 'ออกรายงานผลการเรียนรายบุคคล', 'ส่งออกไฟล์รายงาน'],
    'student-portal': ['นักเรียนเข้าระบบด้วยรหัสนักเรียน', 'ดูผลการเรียน ตารางเรียน การมาเรียน ความประพฤติ', 'เปลี่ยนรหัสผ่านและลืมรหัสผ่าน'],
    'surveys': ['ผู้ดูแลเว็บไซต์สร้างแบบสอบถาม', 'ผู้ตอบเปิดลิงก์สาธารณะและส่งคำตอบ', 'ดูผลสรุปและส่งออก', 'ออกเกียรติบัตรผู้ตอบ'],
    'timetable': ['สร้างตารางสอนของห้อง', 'ตรวจตารางชนกัน', 'ครูดูตารางสอนของตนเอง', 'นักเรียนและผู้ปกครองดูตารางเรียน'],
    'web-cms': ['(นำร่องแล้ว) เขียนข่าว ร่าง ตัวอย่าง เผยแพร่ แก้ไข ยกเลิกเผยแพร่', 'หน้าเนื้อหา อัลบั้ม ดาวน์โหลด บุคลากร กิจกรรม', 'ธีม เมนู hero สไลด์', 'คลังสื่อ', 'ข้อความติดต่อ', 'หน้ากลุ่มงาน/กลุ่มสาระ'],
    'web-ita': ['กรอกและเผยแพร่ข้อมูล ITA ตามข้อ', 'เปิดหน้า ITA สาธารณะและตรวจลิงก์'],
    'web-notices': ['เผยแพร่ประกาศรายชื่อ', 'ผู้เข้าชมค้นหาชื่อในประกาศ'],
    'web-social': ['เชื่อมต่อเพจ Facebook', 'ดึงโพสต์มาแสดงเป็นข่าว', 'ซ่อนหรือลบโพสต์ที่ไม่ต้องการ'],
}

GROUPS = [
    {'id': 'G1', 'name': 'ข้อมูลหลักและผลการเรียน', 'modules': ['academic', 'registry', 'timetable', 'assessment', 'reports-pp', 'exam-checker'],
     'shared_records': 'ปีการศึกษา ห้องเรียน นักเรียน รายวิชา ตารางสอน คะแนน (ตั้งค่าโรงเรียนอยู่ใน academic โมดูลของกลุ่มอื่นอ่านอย่างเดียว)',
     'safe_data': 'ใช้นักเรียน ม.4/1 และ ม.5/1 (ผู้ใช้ student2) และห้อง ม.4/1 ถึง ม.6/1 สำหรับข้อมูลที่สร้างเอง'},
    {'id': 'G2', 'name': 'ชีวิตนักเรียนและพอร์ทัล', 'modules': ['student-portal', 'guardian-portal', 'behavior', 'health', 'activities', 'learning-links', 'constructionism'],
     'shared_records': 'บันทึกพฤติกรรม สุขภาพ ชุมนุม ลิงก์ห้องเรียน บัญชีพอร์ทัล (อ่านรายชื่อนักเรียนจาก registry)',
     'safe_data': 'ใช้นักเรียน ม.1/1 เลขที่ 2 ถึง 10 และ ม.2/1 (ห้ามแตะเลขที่ 1 กวิน ธารใส ที่ attendance ใช้) ผู้ปกครอง guardian2 (ทอฝัน ม.2/1)'},
    {'id': 'G3', 'name': 'บุคลากรและงานธุรการ', 'modules': ['leave-duty', 'personnel', 'payslip', 'documents', 'maintenance', 'projects'],
     'shared_records': 'บัญชีครู ใบลา เวร สลิปเงินเดือน ทะเบียนหนังสือ แจ้งซ่อม โครงการ (ไม่ใช้ข้อมูลนักเรียน)',
     'safe_data': 'ใช้บัญชี subject, payroll, clerk, principal ข้อมูลที่สร้างใส่เครื่องหมาย {{marker}}'},
    {'id': 'G4', 'name': 'การเงิน เรื่องร้องเรียน แบบสอบถาม และการเชื่อมต่อ', 'modules': ['finance', 'complaints', 'surveys', 'connectors', 'api-gateway'],
     'shared_records': 'ใบแจ้งค่าธรรมเนียม การชำระ เรื่องร้องเรียน แบบสอบถาม ไฟล์นำเข้า คีย์ API',
     'safe_data': 'การเงินใช้นักเรียน ม.5/1 (student2) และบุตร "อคิน" (ม.4/1) ของ guardian1 ไม่ใช่ กวิน แบบสอบถามและเรื่องร้องเรียนสร้างใหม่ทุกครั้งพร้อมเครื่องหมาย'},
    {'id': 'G5', 'name': 'เว็บไซต์สาธารณะและภาพรวม', 'modules': ['web-ita', 'web-notices', 'web-social', 'demo-library', 'operations'],
     'shared_records': 'เนื้อหาเว็บไซต์ (web-cms เป็นของนำร่อง ข่าวที่ผู้ตรวจสร้างมีเครื่องหมายเฉพาะ) ประกาศรายชื่อ ITA โพสต์โซเชียล',
     'safe_data': 'ใช้บัญชี editor เหมือนนำร่อง web-cms แต่สร้างเนื้อหาของโมดูลตนเอง และลบเมื่อจบ'},
]


# Usage areas that are not modules (core application): written by hand from routes/*.php, SchoolNavigation.php and artisan list.
AREAS = [
    {'id': 'account-signin', 'name': 'เข้าสู่ระบบ ลืมรหัสผ่าน และบัญชีของฉัน', 'group': 'G3', 'roles': ['ทุกบทบาทของเจ้าหน้าที่'],
     'pages': ['/login', '/forgot-password', '/reset-password/{token}', '/account', 'POST /logout'],
     'tasks': ['เข้าสู่ระบบและออกจากระบบ', 'ขอลิงก์ตั้งรหัสผ่านใหม่และตั้งรหัสใหม่', 'เปลี่ยนรหัสผ่านที่หน้าบัญชีของฉัน', 'ดูและเพิกถอนการเข้าระบบอื่น (account/sessions/revoke)'],
     'notes': 'อีเมลลิงก์ตั้งรหัสผ่านต้องมีตัวส่งเมลในเครื่องทดลอง ถ้าไม่มีให้ระบุใน untested'},
    {'id': 'account-2fa', 'name': 'ยืนยันตัวตนสองขั้นตอน (2FA)', 'group': 'G3', 'roles': ['ผู้ดูแลระบบและเจ้าหน้าที่ที่เปิดใช้'],
     'pages': ['/account (ส่วนความปลอดภัย)', '/login/mfa', 'POST /account/mfa/start|confirm|disable'],
     'tasks': ['เปิดใช้ 2FA และยืนยันรหัส', 'เข้าสู่ระบบด้วยรหัส 2FA', 'ปิด 2FA'],
     'notes': 'รหัสแบบครั้งเดียวต้องซ่อนในภาพ (ใช้ mask) ห้ามเปิดใช้ 2FA กับบัญชีที่ใช้ร่วมกัน ให้สร้างผู้ใช้ทดสอบใหม่ใน admin-users'},
    {'id': 'admin-users', 'name': 'จัดการผู้ใช้งาน', 'group': 'G1', 'roles': ['ผู้ดูแลระบบ'],
     'pages': ['/admin/users', 'POST /admin/users', 'PUT /admin/users/{id}', 'POST /admin/users/{id}/password-link'],
     'tasks': ['เพิ่มผู้ใช้และกำหนดบทบาท', 'แก้ข้อมูล ปิดการใช้งาน', 'ส่งลิงก์ตั้งรหัสผ่านให้ผู้ใช้'], 'notes': 'ใช้ผู้ใช้ที่สร้างเองด้วย {{marker}} ห้ามแก้บัญชีในไฟล์ accounts'},
    {'id': 'admin-modules', 'name': 'จัดการโมดูลและตั้งค่าโมดูล', 'group': 'G4', 'roles': ['ผู้ดูแลระบบ'],
     'pages': ['/admin/modules', '/whats-new', 'GET|PUT /api/module-settings', 'POST /api/site/modules/{id}/state|export'],
     'tasks': ['ดูรายการโมดูลและระดับความพร้อม', 'เปิดและปิดโมดูล (นโยบาย keep-data)', 'ตั้งค่าโมดูลตาม settings_schema', 'ส่งออกโมดูล', 'ดูหน้า มีอะไรใหม่'],
     'notes': 'ปิดโมดูลแล้วต้องเปิดคืนในขั้นตอน cleanup เสมอ ห้ามปิด academic หรือ web-cms (core-required)'},
    {'id': 'admin-updates', 'name': 'อัปเดตระบบ', 'group': 'G4', 'roles': ['ผู้ดูแลระบบ'],
     'pages': ['/admin/updates (หน้าอัปเดตตัวเอง ที่เอเจนต์อื่นกำลังสร้าง)', 'GET /api/site/updates', 'PUT /api/site/updates/feed', 'POST /api/site/updates/apply', 'artisan saan:update'],
     'tasks': ['ตรวจรุ่นใหม่จากฟีด', 'ดาวน์โหลดและติดตั้งแพ็กเกจที่ลงนาม', 'ล้างไฟล์สำรองเก่า'],
     'notes': 'ห้ามกดติดตั้งจริงบนระบบตรวจ (เปลี่ยนรุ่นของตัวเครื่อง) ตรวจเฉพาะการแสดงสถานะ การตรวจรุ่น และข้อความเมื่อไม่มีฟีด ที่เหลือใส่ untested'},
    {'id': 'backup-restore', 'name': 'สำรองและกู้คืนข้อมูล', 'group': 'G4', 'roles': ['ผู้ดูแลระบบ (บรรทัดคำสั่ง)'],
     'pages': ['ไม่มีหน้าเว็บ: การสำรองอยู่ในขั้นตอนของ artisan saan:update และคู่มือ docs/installation.th.md', 'wiki install/backup (saan-site/content/wiki/install/backup.md)'],
     'tasks': ['ตรวจว่าคู่มือบอกขั้นตอนสำรองตรงกับสิ่งที่ saan:update ทำจริง', 'ตรวจว่าไม่มีปุ่มหรือเมนูสำรองที่สัญญาไว้แต่ไม่ทำงาน'],
     'notes': 'ส่วนใหญ่เป็นงานตรวจ skeleton จากการอ่านโค้ด/คู่มือ ไม่มี workflow ในเบราว์เซอร์ ให้ระบุเหตุผลใน scenario'},
    {'id': 'installer-pointers', 'name': 'ตัวติดตั้ง ตัวอัปเดต และคู่มือติดตั้ง (ทางไปหา)', 'group': 'G5', 'roles': ['ผู้ติดตั้งระบบ'],
     'pages': ['install.php และโฟลเดอร์ installer/', 'docs/installation.th.md (คู่มือติดตั้ง)', 'wiki install/install, install/update, install/health-check'],
     'tasks': ['ชี้ไปยังคู่มือติดตั้ง ไม่ต้องรันตัวติดตั้งซ้ำบนระบบตรวจ', 'ตรวจว่าลิงก์และภาพในคู่มือติดตั้งเปิดได้ (http_status)'],
     'notes': 'ห้ามรัน install.php บน audit-070 เพราะทับระบบตรวจ'},
    {'id': 'admin-publish-pairing', 'name': 'เผยแพร่เต็มรูปแบบและจับคู่เว็บสาธารณะ', 'group': 'G5', 'roles': ['ผู้ดูแลระบบ'],
     'pages': ['/admin/publish', '/admin/pairing', 'POST /admin/publish/full', 'POST /admin/pairing/code'],
     'tasks': ['ดูสถานะการส่งเนื้อหาไปเว็บสาธารณะ', 'ออกรหัสจับคู่ (ใช้ครั้งเดียว ต้อง mask)'],
     'notes': 'ทำงานจริงเฉพาะรุ่นที่แยกเว็บสาธารณะ ในระบบตรวจแบบรวม (combined) อาจแสดงเพียงสถานะ ให้บันทึกเป็นผลสังเกต'},
    {'id': 'public-website', 'name': 'เว็บไซต์สาธารณะของโรงเรียน (ผู้เข้าชม)', 'group': 'G5', 'roles': ['ผู้เข้าชมทั่วไป'],
     'pages': ['/', '/portal', '/portal/news', '/portal/{section}', '/portal/{section}/{slug}', '/portal/search', '/portal/directory', '/portal/contact', '/portal/groups', '/portal/ita', '/portal/complaints', '/sitemap.xml'],
     'tasks': ['เปิดหน้าแรกและเมนูทุกรายการ', 'อ่านข่าว ดาวน์โหลดเอกสาร ดูอัลบั้ม', 'ค้นหา', 'ส่งแบบฟอร์มติดต่อ (ต้องระวังอีเมลและ throttle)', 'ดูหน้ากลุ่มงาน'],
     'notes': 'งานข่าวฝั่งผู้ดูแลเป็นของ web-cms (นำร่องแล้ว) ที่นี่ตรวจด้านผู้เข้าชม ใช้ role anonymous'},
    {'id': 'web-site-settings', 'name': 'ตั้งค่าหน้าตาและข้อมูลของเว็บไซต์โรงเรียน', 'group': 'G5', 'roles': ['ผู้ดูแลเว็บไซต์', 'ผู้ดูแลระบบ'],
     'pages': ['/cms#branding', '/cms#header', '/cms#hero-design', '/cms#slides', '/cms#observance', '/cms#identity', '/cms#profile', '/cms#homepage', '/cms#categories', '/cms#audit',
               '/cms#redirects', '/cms#mail', '/cms#deployment'],
     'tasks': ['ชื่อ โลโก้ และสีประจำโรงเรียน', 'ส่วนหัวและเมนู', 'ออกแบบ hero และสไลด์หน้าแรก', 'ไว้ทุกข์ / เฉลิมฉลอง', 'favicon และโหมดสี', 'จัดหน้าแรก', 'ข้อมูลโรงเรียน', 'หมวดเนื้อหา',
               'ประวัติการจัดการ', 'Redirect URL เดิม', 'ตั้งค่าการส่งอีเมล', 'ตรวจความพร้อมระบบ'],
     'notes': 'ค่าของทั้งเว็บไซต์: ทุก workflow เปลี่ยนค่า ตรวจผลบนหน้าเว็บสาธารณะ แล้วคืนค่าเดิม จึงต้องรันบนระบบทดสอบส่วนตัวที่ล้างได้'},
]


def run_routes(install):
    out = subprocess.run([PHP, '-c', PHP_INI, 'artisan', 'route:list', '--json'], cwd=install, capture_output=True, text=True, encoding='utf-8')
    if out.returncode != 0:
        raise SystemExit('[ERR] route:list failed: ' + out.stderr[:300])
    return json.loads(out.stdout)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--install', default=r'D:\AI-Project\School\install-trials\audit-070')
    ap.add_argument('--routes', default=None, help='route list JSON (default: run artisan in --install)')
    ap.add_argument('--out', default=os.path.join(ML.DOCS, 'inventory.json'))
    a = ap.parse_args()
    routes = json.load(open(a.routes, encoding='utf-8-sig')) if a.routes else run_routes(a.install)
    mods_dir = os.path.join(ML.SCHOOL_APP, 'modules')
    assets_dir = os.path.join(ML.SCHOOL_APP, 'public', 'assets', 'modules')
    mods = {}
    for m in sorted(os.listdir(mods_dir)):
        p = os.path.join(mods_dir, m, 'module.json')
        if os.path.exists(p):
            mods[m] = json.load(open(p, encoding='utf-8-sig'))
    ns = {}
    for m in mods:
        src = os.path.join(mods_dir, m, 'src')
        found = None
        for root, _, files in os.walk(src) if os.path.isdir(src) else []:
            for f in files:
                if f.endswith('.php'):
                    mt = re.search(r'^namespace\s+(Saan\\Modules\\[A-Za-z0-9_]+)', open(os.path.join(root, f), encoding='utf-8').read(), re.M)
                    if mt:
                        found = mt.group(1)
                        break
            if found:
                break
        if found:
            ns[found] = m
    patterns = []
    for m, j in mods.items():
        for pat in j.get('routes', []):
            rx = '^' + re.escape(pat.strip('/')).replace(r'\*', '.*') + '$'
            patterns.append((len(pat), m, re.compile(rx)))
    patterns.sort(key=lambda t: -t[0])
    stat = {m: {'GET': 0, 'POST': 0, 'PUT': 0, 'DELETE': 0, 'roles': set(), 'uris': []} for m in list(mods) + ['core']}
    for r in routes:
        action = r.get('action') or ''
        owner = None
        for n, m in ns.items():
            if action.startswith(n + '\\'):
                owner = m
                break
        if owner is None:
            uri = (r.get('uri') or '').strip('/')
            for _, m, rx in patterns:
                if rx.match(uri):
                    owner = m
                    break
        owner = owner or 'core'
        s = stat[owner]
        for meth in (r.get('method') or '').split('|'):
            meth = 'PUT' if meth == 'PATCH' else meth
            if meth in ('GET', 'POST', 'PUT', 'DELETE'):
                s[meth] += 1
        for mw in r.get('middleware') or []:
            if isinstance(mw, str) and mw.startswith('role:'):
                s['roles'].update(mw[5:].split(','))
        s['uris'].append(r.get('uri'))
    nav_src = open(os.path.join(ML.SCHOOL_APP, 'app', 'Support', 'SchoolNavigation.php'), encoding='utf-8').read()
    nav = re.findall(r"\$item\('([^']+)',\s*'([^']+)',\s*'([^']+)'", nav_src)
    nav_by = {'web-cms': [], 'operations': [], 'core': []}
    for key, label, path in nav:
        k = 'web-cms' if path.startswith('/cms') else 'operations' if path.startswith('/operations') else 'core'
        nav_by[k].append({'key': key, 'label': label, 'path': path})
    area_group = {}
    for ar in AREAS:
        area_group.setdefault(ar['group'], []).append(ar['id'])
    for g in GROUPS:
        g['areas'] = area_group.get(g['id'], [])
        g['units'] = len(g['modules']) + len(g['areas'])
    out = {'areas': AREAS, 'generated_from': {'install': a.install, 'route_count': len(routes),
                              'note': 'routes attributed by PHP namespace, else by module.json routes patterns; "core" = everything else (login, portals, admin pages)'},
           'modules': [], 'core': {}, 'groups': GROUPS}
    for m, j in mods.items():
        menu = [{'label': e['label'], 'path': e['path'], 'roles': e.get('roles', []), 'where': 'work_menu'} for e in j.get('work_menu', [])]
        menu += [{'label': e['label'], 'path': e['path'], 'roles': e.get('roles', []), 'where': 'admin_menu'} for e in j.get('admin_menu', [])]
        menu += [dict(e, roles=['admin', 'principal', 'editor'] if m == 'web-cms' else ['admin', 'principal', 'teacher'], where='core navigation (SchoolNavigation.php)') for e in nav_by.get(m, [])]
        assets = list(j.get('work_assets', [])) + list(j.get('admin_assets', []))
        d = os.path.join(assets_dir, m)
        on_disk = sorted(os.listdir(d)) if os.path.isdir(d) else []
        s = stat[m]
        roles = set(s['roles'])
        for e in menu:
            roles.update(e['roles'])
        grp = next((g['id'] for g in GROUPS if m in g['modules']), 'pilot' if m in ('attendance', 'web-cms') else None)
        public_routes = [u for u in s['uris'] if u and (u.startswith('portal') or u.startswith('learn') or u.startswith('api/public'))]
        total = s['GET'] + s['POST'] + s['PUT'] + s['DELETE']
        rdir = os.path.join(mods_dir, m, 'routes')
        out['modules'].append({
            'id': m, 'name': j['name'], 'readiness': j['readiness'], 'version': j.get('version'), 'description': j['description'],
            'staff_menu': menu, 'routes': {k: s[k] for k in ('GET', 'POST', 'PUT', 'DELETE')} | {'total': total},
            'public_route_count': len(public_routes), 'route_files': sorted(os.listdir(rdir)) if os.path.isdir(rdir) else [],
            'assets_declared': assets, 'assets_on_disk': on_disk,
            'roles': sorted(roles), 'has_menu_entry': bool(menu), 'has_ui_asset': bool(assets or on_disk),
            'flags': [f for f, c in (('ไม่มีรายการเมนูของเจ้าหน้าที่', not menu), ('ไม่มีไฟล์ JS/CSS ของหน้าจอ', not (assets or on_disk)), ('ไม่มีเส้นทาง (route) ที่ระบบกำหนดให้โมดูลนี้', total == 0)) if c],
            'first_look_workflows': WORKFLOWS.get(m, []), 'group': grp,
            'scenario': os.path.exists(os.path.join(ML.DEFAULT_SCENARIOS, m + '.json')),
        })
    c = stat['core']
    out['core'] = {'routes': {k: c[k] for k in ('GET', 'POST', 'PUT', 'DELETE')}, 'navigation': nav_by['core']}
    os.makedirs(os.path.dirname(a.out), exist_ok=True)
    json.dump(out, open(a.out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    flagged = [(x['id'], x['flags']) for x in out['modules'] if x['flags']]
    print('[OK] %s: %d modules, %d routes (core %d), flagged: %s' % (a.out, len(out['modules']), len(routes), sum(c[k] for k in ('GET', 'POST', 'PUT', 'DELETE')), flagged))


if __name__ == '__main__':
    main()
