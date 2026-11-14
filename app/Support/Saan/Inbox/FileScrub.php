<?php

namespace App\Support\Saan\Inbox;

use App\Support\Saan\Publish\Wire;

/**
 * Strips what identifies the sender from the files of an anonymous submission, on the public side before anything is sealed.
 * Images are decoded and re-encoded (GD writes no EXIF, XMP or ICC comment), OOXML documents get docProps/core.xml, app.xml and custom.xml replaced by
 * empty ones (no creator, last-modified-by or company), and the file name becomes a neutral "attachment-N.ext". Formats whose metadata cannot be removed
 * safely here (PDF Info/XMP, legacy .doc/.xls OLE streams) are refused in anonymous mode (fail closed).
 */
final class FileScrub
{
    private const IMAGES = ['jpg', 'jpeg', 'png', 'webp'];
    private const OOXML = ['docx', 'xlsx'];
    private const PLAIN = ['txt'];

    /** @param list<array{name:string,mime:string,size:int,sha256:string,content:string}> $files @return list<array{name:string,mime:string,size:int,sha256:string,content:string}> */
    public static function anonymous(array $files): array
    {
        $out = [];
        foreach (array_values($files) as $i => $f) {
            $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            if (in_array($ext, self::IMAGES, true)) $content = self::image((string) $f['content'], $ext);
            elseif (in_array($ext, self::OOXML, true)) $content = self::ooxml((string) $f['content']);
            elseif (in_array($ext, self::PLAIN, true)) $content = (string) $f['content'];
            else throw new InboxRejected('ส่งโดยไม่ระบุชื่อไม่รับไฟล์ชนิด .'.$ext.' เพราะไฟล์อาจมีชื่อผู้สร้างฝังอยู่ กรุณาแนบเป็นรูปภาพ .docx .xlsx หรือ .txt หรือส่งโดยไม่แนบไฟล์', 422);
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
            if (!in_array($mime, Wire::FILE_EXT[$ext] ?? [], true)) throw new InboxRejected('เนื้อไฟล์ไม่ตรงกับนามสกุล .'.$ext, 422);
            $out[] = ['name' => 'attachment-'.($i + 1).'.'.$ext, 'mime' => $mime, 'size' => strlen($content), 'sha256' => hash('sha256', $content), 'content' => $content];
        }
        return $out;
    }

    private static function image(string $bytes, string $ext): string
    {
        if (!function_exists('imagecreatefromstring')) throw new InboxRejected('เซิร์ฟเวอร์ยังรับรูปแนบแบบไม่ระบุชื่อไม่ได้ (ไม่มีส่วนขยาย GD)', 503);
        $im = @imagecreatefromstring($bytes);
        if ($im === false) throw new InboxRejected('อ่านรูปแนบไม่ได้', 422);
        ob_start();
        try {
            if ($ext === 'png') { imagealphablending($im, false); imagesavealpha($im, true); $ok = imagepng($im); }
            elseif ($ext === 'webp') { imagepalettetotruecolor($im); imagealphablending($im, false); imagesavealpha($im, true); $ok = function_exists('imagewebp') && imagewebp($im, null, 85); }
            else { $ok = imagejpeg($im, null, 90); }
        } finally {
            $out = (string) ob_get_clean();
            imagedestroy($im);
        }
        if (!$ok || $out === '') throw new InboxRejected('จัดการรูปแนบไม่สำเร็จ', 422);
        return $out;
    }

    /**
     * Beyond docProps: comment and tracked-change authors (docx w:author/w:initials, xlsx comments, pptx comment authors, persons, revision user names), word/people.xml and
     * its relationship, the local paths x15ac:absPath and w:attachedTemplate (with its relationship), the package thumbnail, and the EXIF of embedded images (re-encoded through GD). A part that cannot be scrubbed safely
     * (embedded OLE objects, macros, media formats other than jpg/png/webp/gif/emf/wmf) refuses the whole file.
     */
    private static function ooxmlDeep(\ZipArchive $zip): void
    {
        $refuse = fn () => throw new InboxRejected('เอกสารแนบมีส่วนที่ลบข้อมูลผู้สร้างออกให้ปลอดภัยไม่ได้ กรุณาแนบเป็นรูปภาพหรือไฟล์ .txt หรือส่งโดยไม่แนบไฟล์', 422);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) $names[] = (string) $zip->getNameIndex($i);
        foreach ($names as $n) {
            $l = strtolower($n);
            if (str_ends_with($n, '/')) continue;
            // Excel stores the page set-up of the author's printer (printer and driver name) in xl/printerSettings/*.bin for most workbooks:
            // the part goes together with its relationship and the r:id of <pageSetup> (both below), instead of refusing nearly every xlsx
            if (preg_match('#^(xl|ppt|word)/printersettings/[^/]+\.bin$#', $l)) { $zip->deleteName($n); continue; }
            if (preg_match('#(^|/)(embeddings|activex|vbaproject)#', $l) || str_ends_with($l, '.bin')) $refuse();
            if (preg_match('#(^|/)people\.xml$#', $l) || preg_match('#^docprops/thumbnail\.[a-z]+$#', $l)) { $zip->deleteName($n); continue; }
            if (preg_match('#/media/[^/]+\.([a-z0-9]+)$#', $l, $m)) {
                $e = $m[1];
                if (in_array($e, ['jpg', 'jpeg', 'png', 'webp'], true)) $zip->addFromString($n, self::image((string) $zip->getFromName($n), $e === 'jpeg' ? 'jpg' : $e));
                elseif (!in_array($e, ['gif', 'emf', 'wmf'], true)) $refuse();
                continue;
            }
            if (!preg_match('#\.(xml|rels)$#', $l) || preg_match('#^docprops/(core|app|custom)\.xml$#', $l)) continue; // docProps were already replaced by empty parts
            $x = $zip->getFromName($n);
            if ($x === false) $refuse();
            $y = $x;
            if (str_ends_with($l, '.rels')) {
                $y = preg_replace('#<Relationship\b[^>]*Target="[^"]*(?:people\.xml|docProps/thumbnail\.[a-z]+)"[^>]*/>#i', '', $y);
                // the attached template is a path on the author's machine or file server (file:///C:/Users/<name>/...): the relationship goes, and w:attachedTemplate below with it
                if ($y !== null) $y = preg_replace('#<Relationship\b[^>]*Type="[^"]*/(?:attachedTemplate|printerSettings)"[^>]*/>#i', '', $y);
            } elseif ($l === '[content_types].xml') {
                $y = preg_replace('#<Override\b[^>]*PartName="/(?:word/people\.xml|docProps/thumbnail\.[a-z]+)"[^>]*/>#i', '', $y);
            } else {
                $y = preg_replace('#\b(w:author|w:initials|userName|w15:userId|w15:providerId)="[^"]*"#', '$1=""', $y);
                $y = preg_replace('#(<x15ac:absPath\b[^>]*?\burl=)"[^"]*"#', '$1""', $y);
                if ($y !== null) $y = preg_replace('#<w:attachedTemplate\b[^>]*/>#', '', $y);
                if ($y !== null) $y = preg_replace('#(<pageSetup\b[^>]*?)\s+r:id="[^"]*"#', '$1', $y); // pointed at the removed printer settings part
                $y = preg_replace('#(<author\b[^>]*>)[^<]*(</author>)#', '$1$2', $y);
                if (preg_match('#(commentauthors|/authors|persons?/|person)[^/]*\.xml$#', $l)) $y = preg_replace('#\b(name|initials|displayName|userId|providerId)="[^"]*"#', '$1=""', $y);
            }
            if ($y === null) $refuse();
            if ($y !== $x) {
                $zip->addFromString($n, $y);
                if (in_array($n, ['[Content_Types].xml', '_rels/.rels'], true)) $zip->setCompressionName($n, \ZipArchive::CM_STORE); // keeps the layout finfo recognises
            }
        }
    }

    private static function ooxml(string $bytes): string
    {
        if (!class_exists(\ZipArchive::class)) throw new InboxRejected('เซิร์ฟเวอร์ยังรับเอกสารแนบแบบไม่ระบุชื่อไม่ได้ (ไม่มีส่วนขยาย zip)', 503);
        $tmp = tempnam(sys_get_temp_dir(), 'scrub');
        try {
            file_put_contents($tmp, $bytes);
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) throw new InboxRejected('อ่านเอกสารแนบไม่ได้', 422);
            $empty = [
                'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"></cp:coreProperties>',
                'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"></Properties>',
                'docProps/custom.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties"></Properties>',
            ];
            foreach ($empty as $name => $xml) if ($zip->locateName($name) !== false) $zip->addFromString($name, $xml);
            self::ooxmlDeep($zip);
            $zip->close();
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
