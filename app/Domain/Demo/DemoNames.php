<?php

namespace App\Domain\Demo;

/**
 * Invented names and artificial numbers for the trial school. Nothing here is copied from a list of real people:
 * given names and family names are made-up combinations, identity numbers start with 0 (a first digit the Thai
 * registry never issues) and phone numbers use the prefix 000, which no Thai operator owns.
 */
final class DemoNames
{
    public const SCHOOL = 'โรงเรียนต้นธารวิทยา';
    public const ADDRESS = '99 หมู่ 9 ตำบลต้นธาร อำเภอธารทอง จังหวัดสมมติ 99999 (ที่อยู่สมมติ)';
    public const PHONE = '000-555-0100';
    public const MAIL_DOMAIN = 'tonthan.example.test';

    public const MALE = ['กวิน', 'ขจร', 'คณิน', 'จิรัฏฐ์', 'ชนก', 'ชวิน', 'ฐากร', 'ณภัทร', 'ดนัย', 'ตะวัน', 'ทัพฟ้า', 'ธนัท', 'นที', 'นวิน', 'ปกรณ์', 'ปริญ', 'พชร', 'พีรวิชญ์', 'ภูผา', 'ภาคิน',
        'มนัส', 'ยศกร', 'รชต', 'รัชพล', 'วริทธิ์', 'วายุ', 'ศิลา', 'ศุภณัฐ', 'สิรวิชญ์', 'สุเมธ', 'อคิน', 'อชิระ', 'อนันต์', 'อิทธิ', 'เอกภพ', 'ไตรภูมิ', 'กฤติน', 'ธีรเมธ', 'ปัณณวิชญ์', 'วชิรวิทย์', 'ณัฐดนัย', 'ภูริช', 'คีตา', 'ธารา'];
    public const FEMALE = ['กชกร', 'ขวัญข้าว', 'จันทร์เจ้า', 'ชลธาร', 'ชนิดา', 'ญาดา', 'ฐิตา', 'ณิชา', 'ดารินทร์', 'ตรีชฎา', 'ทอฝัน', 'ธัญรดา', 'นลิน', 'น้ำใส', 'ปริม', 'ปุณณ์', 'พรนภัส', 'พิมพ์มาดา', 'ฟ้าใส', 'ภัทรา',
        'มุกดา', 'เมษา', 'รมิตา', 'รวิสรา', 'ลลิตา', 'วรดา', 'วีรยา', 'ศศิธารา', 'สิรินดา', 'สุพิชญา', 'อนัญญา', 'อรปรียา', 'อัญชิสา', 'ไอริณ', 'เอมิกา', 'กานต์ธิดา', 'ชญานิศ', 'ณัฏฐา', 'ปวริศา', 'พลอยชมพู', 'มนสิชา', 'ลินิน', 'วาดดาว', 'ใบข้าว'];
    public const SURNAMES = ['ธารใส', 'ริมธาร', 'ต้นน้ำดี', 'ทุ่งรวงทอง', 'ผาหมอก', 'ดอยเดือน', 'ห้วยแก้วใส', 'ป่าสักงาม', 'ลานข้าวหอม', 'สวนส้มเย็น', 'ท่าเรือเก่า', 'บ้านไร่ร่ม',
        'คลองดาว', 'นาเกลือขาว', 'เขาเขียวชอุ่ม', 'ปลายฟ้าคราม', 'ไร่อ้อยหวาน', 'ทางเกวียน', 'หาดทรายทอง', 'ร่มไทรใหญ่', 'ศาลาริมน้ำ', 'สะพานไม้เก่า', 'ตลาดเช้า', 'ยุ้งข้าวเต็ม',
        'ฝายน้ำล้น', 'โค้งตะวัน', 'เนินลมโชย', 'ดงตาลคู่', 'หนองบัวบาน', 'ลำธารทอง', 'กอไผ่งาม', 'ต้นจันทน์หอม', 'เรือนไม้หอม', 'ทุ่งดอกคูน', 'ชายเขางาม', 'วังปลาชุม',
        'ท้องนาเขียว', 'สายหมอกจาง', 'ดาวเหนือสว่าง', 'แสงเช้าใส', 'ลมหนาวมา', 'ฟ้าหลังฝน', 'ใบไม้ผลิ', 'น้ำค้างยอดหญ้า', 'รุ้งสองสาย', 'เมฆขาวลอย', 'จันทร์กระจ่าง', 'ตะวันรุ่งเช้า'];

    /**
     * Staff of the trial school. Index 0 is the principal (not in the timetable); 1-12 teach. `account` names the listed trial account
     * of that person, `portrait` a fictional portrait that already ships with the website sample (resources/install/demo/media).
     * Columns: prefix, first, last, subject group, position, standing, work group, account, portrait.
     */
    public const STAFF = [
        ['ดร.', 'ต้นธาร', 'เรียนดี', 'บริหารสถานศึกษา', 'ผู้อำนวยการโรงเรียน', 'ผู้อำนวยการชำนาญการพิเศษ', 'ฝ่ายบริหาร', 'principal', '0083.webp'],
        ['นางสาว', 'พิมพ์ใจ', 'ใฝ่รู้', 'ภาษาไทย', 'ครู', 'ครูชำนาญการ', 'กลุ่มบริหารวิชาการ', 'homeroom', '0085.webp'],
        ['นาย', 'เมธา', 'ทดลอง', 'วิทยาศาสตร์และเทคโนโลยี', 'ครู', 'ครูชำนาญการ', 'กลุ่มบริหารวิชาการ', null, '0087.webp'],
        ['นางสาว', 'รินรดา', 'อ่านเขียน', 'ภาษาไทย', 'ครู', 'ครู', 'กลุ่มบริหารวิชาการ', null, '0089.webp'],
        ['นาง', 'ธารา', 'อ่อนโยน', 'สังคมศึกษา ศาสนา และวัฒนธรรม', 'ครู', 'ครูชำนาญการพิเศษ', 'กลุ่มบริหารงานบุคคล', null, '0091.webp'],
        ['นาย', 'ปัณณ์', 'คิดคำนวณ', 'คณิตศาสตร์', 'ครู', 'ครูชำนาญการ', 'กลุ่มบริหารวิชาการ', null, null],
        ['นางสาว', 'ลลิล', 'สื่อสาร', 'ภาษาต่างประเทศ', 'ครู', 'ครู', 'กลุ่มบริหารทั่วไป', null, null],
        ['นาย', 'กันตภณ', 'โจทย์ยาก', 'คณิตศาสตร์', 'ครู', 'ครู', 'กลุ่มบริหารวิชาการ', 'subject', null],
        ['นางสาว', 'วรินทร์', 'ทดสอบดี', 'วิทยาศาสตร์และเทคโนโลยี', 'ครู', 'ครูชำนาญการ', 'กลุ่มบริหารงบประมาณ', 'finance', null],
        ['นาย', 'ชยุต', 'วิ่งไว', 'สุขศึกษาและพลศึกษา', 'ครู', 'ครู', 'กลุ่มบริหารงานบุคคล', 'payroll', null],
        ['นางสาว', 'ศิริน', 'วาดฝัน', 'ศิลปะ', 'ครู', 'ครู', 'กลุ่มบริหารทั่วไป', 'clerk', null],
        ['นาย', 'ธนิน', 'ลงมือทำ', 'การงานอาชีพ', 'ครู', 'ครูผู้ช่วย', 'กลุ่มบริหารทั่วไป', null, null],
        ['นางสาว', 'อลิน', 'พูดเก่ง', 'ภาษาต่างประเทศ', 'ครู', 'ครู', 'กลุ่มบริหารวิชาการ', null, null],
    ];

    /**
     * Subjects of the timetable and the assessment courses: key => [label, code letter, periods per week, credit,
     * teacher index for ม.1-ม.3, teacher index for ม.4-ม.6, special room or null]. 32 periods; three more are homeroom activities.
     */
    public const SUBJECTS = [
        'thai' => ['ภาษาไทย', 'ท', 5, 1.5, 1, 3, null],
        'math' => ['คณิตศาสตร์', 'ค', 5, 1.5, 5, 7, null],
        'science' => ['วิทยาศาสตร์และเทคโนโลยี', 'ว', 5, 1.5, 2, 8, 'ห้องปฏิบัติการวิทยาศาสตร์'],
        'social' => ['สังคมศึกษา ศาสนา และวัฒนธรรม', 'ส', 4, 1.5, 4, 4, null],
        'english' => ['ภาษาอังกฤษ', 'อ', 5, 1.5, 6, 12, null],
        'health' => ['สุขศึกษาและพลศึกษา', 'พ', 3, 1.0, 9, 9, 'สนามกีฬา'],
        'art' => ['ศิลปะ', 'ศ', 2, 1.0, 10, 10, 'ห้องศิลปะ'],
        'work' => ['การงานอาชีพ', 'ง', 3, 1.0, 11, 11, 'ห้องคอมพิวเตอร์'],
    ];

    /** Thai national ID that passes the mod-11 check digit, from a sequence no real person holds (first digit 0, then 9999). */
    public static function thaiId(int $seq): string
    {
        $d = '09999'.sprintf('%07d', $seq % 10000000);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) $sum += (int) $d[$i] * (13 - $i);
        return $d.((11 - $sum % 11) % 10);
    }

    public static function phone(int $n): string
    {
        return '000-555-'.sprintf('%04d', $n % 10000);
    }

    /** Random password of unambiguous letters and digits; always has a lower-case letter, an upper-case letter and a digit. */
    public static function password(int $length = 14): string
    {
        $sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789'];
        $all = implode('', $sets);
        do {
            $p = '';
            for ($i = 0; $i < $length; $i++) $p .= $all[random_int(0, strlen($all) - 1)];
            $ok = true;
            foreach ($sets as $set) if (strpbrk($p, $set) === false) $ok = false;
        } while (!$ok);
        return $p;
    }

    /** Deterministic pseudo-random number 0..$mod-1 from a label, so the same trial data comes out on every install. */
    public static function pick(string $label, int $mod): int
    {
        return $mod > 0 ? (int) (hexdec(substr(hash('sha256', 'saan-demo|'.$label), 0, 7)) % $mod) : 0;
    }

    /** Student $k (0-based over the whole school): [sex, prefix, first name, family name]. Pairs never repeat within 120 students. */
    public static function student(int $k, int $grade): array
    {
        $male = $k % 2 === 0;
        $g = intdiv($k, 2);
        $first = $male ? self::MALE[$g % count(self::MALE)] : self::FEMALE[$g % count(self::FEMALE)];
        $last = self::SURNAMES[($k * 7) % count(self::SURNAMES)];
        $prefix = $grade <= 3 ? ($male ? 'เด็กชาย' : 'เด็กหญิง') : ($male ? 'นาย' : 'นางสาว');
        return [$male ? 'M' : 'F', $prefix, $first, $last];
    }

    /** Guardian number $n of student $k: [relationship, prefix, first name]. The family name is the student's. */
    public static function guardian(int $k, int $n): array
    {
        $mother = ($k + $n) % 2 === 0;
        return $mother
            ? ['mother', 'นาง', self::FEMALE[($k * 11 + 5 + $n * 17) % count(self::FEMALE)]]
            : ['father', 'นาย', self::MALE[($k * 13 + 3 + $n * 19) % count(self::MALE)]];
    }
}
