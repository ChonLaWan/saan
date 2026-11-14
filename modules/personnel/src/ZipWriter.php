<?php

namespace Saan\Modules\Personnel;

/**
 * Minimal streaming ZIP writer (method STORE, UTF-8 names, data descriptors, no zip64).
 * Nothing is written to disk: every chunk goes straight to the $sink callback, so memory stays flat
 * and no temporary file exists. Callers must keep the archive under 4 GB and 65535 entries.
 */
class ZipWriter
{
    private int $offset = 0;
    private array $central = [];

    /** @param \Closure(string):void $sink */
    public function __construct(private \Closure $sink) {}

    private function out(string $bytes): void
    {
        $this->offset += strlen($bytes);
        ($this->sink)($bytes);
    }

    /** @param resource $stream */
    public function addStream(string $name, $stream, int $mtime): void
    {
        $name = str_replace('\\', '/', $name);
        [$time, $date] = $this->dos($mtime);
        $start = $this->offset;
        $flags = 0x0808; // bit 3: sizes follow the data; bit 11: UTF-8 name
        $this->out(pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, 0, $time, $date, 0, 0, 0, strlen($name), 0).$name);
        $hash = hash_init('crc32b'); $size = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 1048576);
            if ($chunk === false || $chunk === '') break;
            hash_update($hash, $chunk); $size += strlen($chunk);
            $this->out($chunk);
        }
        $crc = (int) hexdec(hash_final($hash));
        $this->out(pack('VVVV', 0x08074b50, $crc, $size, $size));
        $this->central[] = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flags, 0, $time, $date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $start).$name;
    }

    public function finish(): void
    {
        $cdStart = $this->offset; $cdSize = 0;
        foreach ($this->central as $entry) { $cdSize += strlen($entry); $this->out($entry); }
        $n = count($this->central);
        $this->out(pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, $cdSize, $cdStart, 0));
    }

    private function dos(int $ts): array
    {
        $d = getdate(max($ts, 315532800)); // DOS time starts in 1980
        return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
    }
}
