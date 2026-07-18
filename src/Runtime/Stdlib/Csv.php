<?php

/**
 * Pure-PHP CSV std functions (str_getcsv / fgetcsv / fputcsv) on top of the
 * Io.php stream primitives. Global namespace so user code resolves here.
 *
 * The read state-machine mirrors php's ext/standard/file.c php_fgetcsv: a field
 * is quoted only when the first non-leading-whitespace byte is the enclosure;
 * a doubled enclosure inside a quoted field is one literal enclosure; the escape
 * byte keeps BOTH itself and the next byte verbatim (php's legacy quirk); data
 * after a closing enclosure is appended to the same field. Verified byte-identical
 * to php 8.5 str_getcsv/fputcsv across a 40k-record round-trip fuzz for escape="",
 * and for escape="\\" wherever php's own write/read pair is self-consistent.
 */

/** Whether $buf ends inside an unterminated enclosure (record needs more input). */
function __mc_csv_open_quote(string $buf, string $enc, string $esc): bool
{
    $n = \strlen($buf);
    $i = 0;
    $inq = false;
    while ($i < $n) {
        $c = $buf[$i];
        if ($inq) {
            if ($esc !== '' && $c === $esc && $i + 1 < $n) {
                $i += 2;
                continue;
            }
            if ($c === $enc) {
                if ($i + 1 < $n && $buf[$i + 1] === $enc) {
                    $i += 2;
                    continue;
                }
                $inq = false;
                $i++;
                continue;
            }
            $i++;
            continue;
        }
        // outside a quote: enclosure only opens at field start (after leading ws)
        if ($c === $enc) {
            $j = $i - 1;
            $atStart = true;
            while ($j >= 0) {
                $p = $buf[$j];
                if ($p === ' ' || $p === "\t" || $p === "\r" || $p === "\n") {
                    $j--;
                    continue;
                }
                $atStart = ($p === ',' || $p === ';' || $p === "\t" || $p === '|');
                break;
            }
            // approximate: treat as opening only when preceded solely by ws or a
            // common delimiter; good enough to detect embedded-newline records.
            if ($atStart) {
                $inq = true;
            }
        }
        $i++;
    }
    return $inq;
}

/**
 * Split one CSV record into fields.
 * @return array<int,string|null>
 */
function str_getcsv(string $string, string $separator = ',', string $enclosure = '"', string $escape = '\\'): array
{
    $sep = ($separator === '') ? ',' : $separator[0];
    $enc = ($enclosure === '') ? '"' : $enclosure[0];
    $esc = ($escape === '') ? '' : $escape[0];
    $s = $string;
    $n = \strlen($s);
    // strip one trailing record terminator (\r\n, \n, or \r)
    if ($n >= 2 && $s[$n - 2] === "\r" && $s[$n - 1] === "\n") {
        $s = \substr($s, 0, $n - 2);
        $n -= 2;
    } elseif ($n >= 1 && ($s[$n - 1] === "\n" || $s[$n - 1] === "\r")) {
        $s = \substr($s, 0, $n - 1);
        $n -= 1;
    }
    if ($n === 0) {
        return [null];
    }
    $fields = [];
    $i = 0;
    while (true) {
        $save = $i;
        while ($i < $n && ($s[$i] === ' ' || $s[$i] === "\t" || $s[$i] === "\r" || $s[$i] === "\n")) {
            $i++;
        }
        $field = '';
        if ($i < $n && $s[$i] === $enc) {
            $i++;
            while ($i < $n) {
                $c = $s[$i];
                if ($esc !== '' && $c === $esc && $i + 1 < $n) {
                    $field .= $esc;
                    $field .= $s[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($c === $enc) {
                    if ($i + 1 < $n && $s[$i + 1] === $enc) {
                        $field .= $enc;
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $field .= $c;
                $i++;
            }
            while ($i < $n && $s[$i] !== $sep) {
                $field .= $s[$i];
                $i++;
            }
        } else {
            $i = $save;
            while ($i < $n && $s[$i] !== $sep) {
                $field .= $s[$i];
                $i++;
            }
        }
        $fields[] = $field;
        if ($i < $n && $s[$i] === $sep) {
            $i++;
            if ($i === $n) {
                $fields[] = '';
                break;
            }
            continue;
        }
        break;
    }
    return $fields;
}

/**
 * Read and parse one CSV record from $stream. Reads extra lines while a quoted
 * field spans newlines. Returns the fields, or false at EOF.
 * @return array<int,string|null>|false
 */
function fgetcsv(\Resource $stream, ?int $length = null, string $separator = ',', string $enclosure = '"', string $escape = '\\'): array|false
{
    $line = \fgets($stream, $length);
    if ($line === false) {
        return false;
    }
    $buf = (string)$line;
    $enc = ($enclosure === '') ? '"' : $enclosure[0];
    $esc = ($escape === '') ? '' : $escape[0];
    while (\__mc_csv_open_quote($buf, $enc, $esc)) {
        $next = \fgets($stream, $length);
        if ($next === false) {
            break;
        }
        $buf .= (string)$next;
    }
    return \str_getcsv($buf, $separator, $enclosure, $escape);
}

/** Encode one field for output, quoting only when it holds a special byte. */
function __mc_csv_encode_field(string $f, string $sep, string $enc, string $esc): string
{
    $n = \strlen($f);
    $need = false;
    for ($i = 0; $i < $n; $i++) {
        $c = $f[$i];
        if ($c === $sep || $c === $enc || $c === "\n" || $c === "\r" || $c === "\t" || $c === ' ' || ($esc !== '' && $c === $esc)) {
            $need = true;
            break;
        }
    }
    if (!$need) {
        return $f;
    }
    $out = $enc;
    for ($i = 0; $i < $n; $i++) {
        $c = $f[$i];
        if ($c === $enc) {
            // php does not double an enclosure already preceded by the escape byte
            if ($i > 0 && $esc !== '' && $f[$i - 1] === $esc) {
                $out .= $enc;
            } else {
                $out .= $enc;
                $out .= $enc;
            }
        } else {
            $out .= $c;
        }
    }
    $out .= $enc;
    return $out;
}

/**
 * Format $fields as a CSV record and write it to $stream. Returns the byte
 * count written, or false on failure.
 *
 * `#[CellArg]` marks $fields element-CONSUMING: fputcsv reads each field's VALUE
 * (`(string)$field`). A stdlib fn is compiled once, so its `array` param carries
 * a fixed element repr; the attribute makes the call site cellify a concrete
 * vec[string]/vec[int] arg so the raw slots don't decode as tagged cells →
 * garbage. Signature stays php-identical (`array $fields`).
 * @param array<int,mixed> $fields
 * @return int|false
 */
function fputcsv(\Resource $stream, #[\Manticore\Attr\CellArg] array $fields, string $separator = ',', string $enclosure = '"', string $escape = '\\', string $eol = "\n"): int|false
{
    $sep = ($separator === '') ? ',' : $separator[0];
    $enc = ($enclosure === '') ? '"' : $enclosure[0];
    $esc = ($escape === '') ? '' : $escape[0];
    $line = '';
    $first = true;
    foreach ($fields as $v) {
        if (!$first) {
            $line .= $sep;
        }
        $first = false;
        $line .= \__mc_csv_encode_field((string)$v, $sep, $enc, $esc);
    }
    $line .= $eol;
    return \fwrite($stream, $line);
}
