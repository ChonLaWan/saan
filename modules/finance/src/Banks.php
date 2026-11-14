<?php

namespace Saan\Modules\Finance;

/** Fixed list of Thai banks a school may receive fees into (code => Thai name). The name is copied onto the account row when it is saved. */
final class Banks
{
    public const LIST = [
        'bbl' => 'ธนาคารกรุงเทพ', 'kbank' => 'ธนาคารกสิกรไทย', 'ktb' => 'ธนาคารกรุงไทย', 'scb' => 'ธนาคารไทยพาณิชย์', 'bay' => 'ธนาคารกรุงศรีอยุธยา',
        'ttb' => 'ธนาคารทหารไทยธนชาต', 'gsb' => 'ธนาคารออมสิน', 'baac' => 'ธนาคารเพื่อการเกษตรและสหกรณ์การเกษตร', 'ghb' => 'ธนาคารอาคารสงเคราะห์',
        'uob' => 'ธนาคารยูโอบี', 'cimb' => 'ธนาคารซีไอเอ็มบี ไทย', 'lhbank' => 'ธนาคารแลนด์ แอนด์ เฮ้าส์', 'kkp' => 'ธนาคารเกียรตินาคินภัทร',
        'tisco' => 'ธนาคารทิสโก้', 'icbc' => 'ธนาคารไอซีบีซี (ไทย)', 'ibank' => 'ธนาคารอิสลามแห่งประเทศไทย', 'scbt' => 'ธนาคารสแตนดาร์ดชาร์เตอร์ด (ไทย)',
    ];

    public static function codes(): array { return array_keys(self::LIST); }

    public static function name(string $code): string { return self::LIST[$code] ?? $code; }

    /** Digits only; null unless 10 to 15 digits (every Thai bank account number fits). */
    public static function normalizeNumber(string $raw): ?string
    {
        if (!preg_match('/^[0-9][0-9 \-]{8,24}$/D', trim($raw))) return null;
        $d = preg_replace('/\D+/', '', $raw);
        return strlen($d) >= 10 && strlen($d) <= 15 ? $d : null;
    }

    /** Last four digits only: what the audit trail keeps of an account number. */
    public static function tail(?string $number): string
    {
        return $number === null || $number === '' ? '' : 'xxxx'.substr($number, -4);
    }
}
