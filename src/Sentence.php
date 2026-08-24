<?php
declare(strict_types=1);

namespace XaNmea;

/**
 * NMEA-0183 sentence: validation, parsing, TAG block handling.
 * Immutable value object. Routing never depends on successful field
 * decode - a structurally valid sentence routes even if unknown.
 */
final class Sentence
{
    public string $raw;          // original line without CRLF and without any TAG block
    public ?string $tagBlock;    // raw TAG block text between \ \ or null (kept for diagnostics)
    public string $body;         // sentence text between $/! and * (or end)
    public string $talker;       // 2 chars (GP, AI, ...)
    public string $type;         // 3 chars (GGA, VDM, ...)
    public string $prefix;       // '$' or '!'
    public ?int $checksum;       // parsed checksum value or null
    public bool $checksumOk;
    /** @var string[] */
    public array $fields;
    public string $srcName = ''; // provenance: input interface name
    public float $ts;            // arrival time (microtime)

    private function __construct() {}

    /**
     * Parse and structurally validate a line. Returns null if the line is
     * not structurally an NMEA sentence at all (routing drops it).
     * $requireChecksum: if true, sentences with a checksum that fails are
     * still returned but flagged checksumOk=false (router decides by config).
     */
    public static function parse(string $line): ?self
    {
        $line = rtrim($line, "\r\n");
        if ($line === '' || strlen($line) > 1024) {
            return null;
        }

        $s = new self();
        $s->raw = $line;
        $s->ts = microtime(true);
        $s->tagBlock = null;

        // Optional TAG block: \....\*hh\ prefix. Parsed for diagnostics but
        // stripped from raw so outputs carry the bare NMEA sentence only.
        $rest = $line;
        if ($rest[0] === '\\') {
            $end = strpos($rest, '\\', 1);
            if ($end === false) {
                return null;
            }
            $s->tagBlock = substr($rest, 1, $end - 1);
            $rest = substr($rest, $end + 1);
            if ($rest === '') {
                return null;
            }
            $s->raw = $rest;
        }

        if ($rest[0] !== '$' && $rest[0] !== '!') {
            return null;
        }
        $s->prefix = $rest[0];
        $rest = substr($rest, 1);

        // Split off checksum
        $s->checksum = null;
        $s->checksumOk = false;
        $star = strpos($rest, '*');
        if ($star !== false) {
            $cs = substr($rest, $star + 1);
            if (!preg_match('/^[0-9A-Fa-f]{2}/', $cs)) {
                return null;
            }
            $s->checksum = hexdec(substr($cs, 0, 2));
            $body = substr($rest, 0, $star);
        } else {
            $body = $rest;
        }
        if (strlen($body) < 5) {
            return null;
        }

        $addr = substr($body, 0, 5);
        // Talker+type, or proprietary $P + 3-char manufacturer, or query.
        if ($addr[0] === 'P') {
            $s->talker = 'P';
            $s->type = substr($addr, 1); // 3-char manufacturer code
        } else {
            $s->talker = substr($addr, 0, 2);
            $s->type = substr($addr, 2, 3);
        }
        if (!preg_match('/^[A-Z0-9]+$/', $addr)) {
            return null;
        }

        $s->body = $body;
        // Data fields follow the 5-char address + separating comma.
        $s->fields = strlen($body) > 6 ? explode(',', substr($body, 6)) : [];

        if ($s->checksum !== null) {
            $s->checksumOk = (self::checksum($body) === $s->checksum);
        }

        return $s;
    }

    /** XOR checksum of all chars between $/! and *. */
    public static function checksum(string $body): int
    {
        $cs = 0;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $cs ^= ord($body[$i]);
        }
        return $cs & 0xFF;
    }

    /** Build a TAG block with checksum, e.g. \s:gps1,c:1718901234*5F\. */
    public static function buildTagBlock(?string $src, ?int $tsSec, ?int $tsMs): ?string
    {
        $parts = [];
        if ($src !== null && $src !== '') {
            $parts[] = 's:' . substr($src, 0, 15);
        }
        if ($tsMs !== null) {
            $parts[] = 'c:' . ($tsMs * 1000); // NMEA 4.x c: is ms * 1000 per IEC 61162-1:2016
        } elseif ($tsSec !== null) {
            $parts[] = 'c:' . $tsSec;
        }
        if (!$parts) {
            return null;
        }
        $t = implode(',', $parts);
        return '\\' . $t . '*' . strtoupper(str_pad(dechex(self::checksum($t)), 2, '0', STR_PAD_LEFT)) . '\\';
    }

    /** Serialize for output, optionally with a fresh TAG block prepended. */
    public function toWire(?string $srcTag, string $timestampMode): string
    {
        $line = $this->raw;
        if ($srcTag !== null || $timestampMode !== 'no') {
            $tag = self::buildTagBlock(
                $srcTag,
                $timestampMode === 's' ? (int)$this->ts : null,
                $timestampMode === 'ms' ? (int)($this->ts * 1000) : null
            );
            if ($tag !== null) {
                $line = $tag . $line;
            }
        }
        return $line . "\r\n";
    }
}
