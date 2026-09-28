<?php

/**
 * Generates src/Runtime/Stdlib/MbstringTables.php FROM ZEND's ext/mbstring.
 *
 * Nothing is hand-typed: the encoding list, every alias and MIME name, and each
 * single-byte encoding's byte → codepoint table are read back from Zend
 * (mb_list_encodings / mb_encoding_aliases / mb_preferred_mime_name /
 * mb_convert_encoding byte by byte), so a name Zend accepts is a name we accept
 * and a byte Zend cannot map is a byte we cannot map.
 *
 * Two tables are not observable from PHP and are read from php-src itself: the
 * 'rare codepoint' bit vector mb_detect_encoding scores candidates with, and
 * the HTML-ENTITIES name list (checked against Zend's encoder here):
 *
 *   B=https://raw.githubusercontent.com/php/php-src/PHP-8.5/ext/mbstring
 *   curl -sfLO $B/rare_cp_bitvec.h; curl -sfLO $B/libmbfl/filters/html_entities.c
 *   php -d xdebug.mode=off tools/gen_mbstring_tables.php rare_cp_bitvec.h html_entities.c
 */

if (!\extension_loaded('mbstring')) {
    \fwrite(STDERR, "needs php with ext/mbstring\n");
    exit(2);
}
\error_reporting(E_ALL & ~E_DEPRECATED);

// The encodings whose every byte is one character (MBFL_ENCTYPE_SBCS in Zend).
$sbcs = ['7bit', '8bit', 'ASCII', 'Windows-1252', 'Windows-1254', 'Windows-1251', 'CP866', 'CP850',
    'KOI8-R', 'KOI8-U', 'ArmSCII-8'];
foreach (\mb_list_encodings() as $e) {
    if (\str_starts_with($e, 'ISO-8859-')) { $sbcs[] = $e; }
}

$list = \mb_list_encodings();
$aliases = [];
$mime = [];
foreach ($list as $e) {
    $aliases[$e] = \mb_encoding_aliases($e);
    try {
        $m = @\mb_preferred_mime_name($e);
        $mime[$e] = $m === false ? '' : $m;
    } catch (\ValueError $x) {
        $mime[$e] = '';
    }
}

\mb_substitute_character('none');
$tables = [];
foreach ($sbcs as $e) {
    if (!\in_array($e, $list, true)) { throw new \RuntimeException("not an mbstring encoding: $e"); }
    $high = [];
    for ($b = 0; $b < 256; $b++) {
        $u = \mb_convert_encoding(\chr($b), 'UTF-8', $e);
        $cp = $u === '' ? -1 : \mb_ord($u, 'UTF-8');
        if ($b < 0x80) {
            // The fast paths assume an ASCII-compatible low half (7bit/ASCII included).
            if ($cp !== $b) { throw new \RuntimeException("$e: byte $b is not ASCII-identical"); }
            continue;
        }
        $high[] = $cp;
    }
    $tables[$e] = $high;
}

// The reverse direction is not the mirror of the decode table: Zend's encoders
// map some codepoints to a byte other than the first one that decodes to them
// (ArmSCII-8 writes U+002D as 0xAD). Every BMP codepoint is encoded through
// Zend, and only the answers that differ from "ASCII as itself, else the first
// byte decoding to it" are stored.
$revFix = [];
foreach ($tables as $e => $high) {
    $naive = [];
    foreach ($high as $i => $cp) { if ($cp >= 0 && !isset($naive[$cp])) { $naive[$cp] = 0x80 + $i; } }
    $fix = [];
    for ($cp = 0; $cp < 0x10000; $cp++) {
        if ($cp >= 0xD800 && $cp <= 0xDFFF) { continue; }
        $b = \mb_convert_encoding(\mb_chr($cp, 'UTF-8'), $e, 'UTF-8');
        $zend = \strlen($b) === 1 ? \ord($b) : -1;
        $ours = $cp < 0x80 ? $cp : ($naive[$cp] ?? -1);
        if ($zend !== $ours) { $fix[$cp] = $zend; }
    }
    if ($fix !== []) { $revFix[$e] = $fix; }
}

// Lead-byte length tables of the multibyte encodings that have one (Zend cuts
// mb_str_split and mb_strcut by them): read back through mb_str_split.
$mblenNames = ['SJIS', 'SJIS-mac', 'SJIS-Mobile#DOCOMO', 'SJIS-Mobile#KDDI', 'SJIS-Mobile#SOFTBANK', 'SJIS-2004',
    'CP932', 'SJIS-win', 'EUC-JP', 'EUC-JP-2004', 'eucJP-win', 'CP51932', 'EUC-CN', 'EUC-TW', 'EUC-KR', 'UHC',
    'CP936', 'BIG-5'];
$mblen = [];
foreach ($mblenNames as $e) {
    $t = '';
    for ($b = 0; $b < 256; $b++) { $t .= (string)\strlen(\mb_str_split(\chr($b) . "\xA1\xA1\xA1\xA1", 1, $e)[0]); }
    $mblen[$e] = $t;
}

// Case mapping and width, per codepoint, straight out of Zend's own tables:
// the full mappings (a string, maybe several codepoints) of upper / lower /
// title / fold, the simple ones where they differ from the full, the two
// properties the context rules read — case-ignorable and cased — and the
// double-width ranges. The properties are recovered from MB_CASE_TITLE_SIMPLE:
// after a cased letter "x" a following "a" stays lower unless w breaks the word
// (not ignorable and not cased); after "." it is raised unless w is cased and
// not ignorable. Where only one of the two answers matters, that is the one read.
$caseFull = [[], [], [], []];
$caseSimple = [[], [], [], []];
$ignorable = [];
$casedIgnorable = [];
$cased = [];
$wide = [];
$modes = [MB_CASE_UPPER, MB_CASE_LOWER, MB_CASE_TITLE, MB_CASE_FOLD];
$simples = [MB_CASE_UPPER_SIMPLE, MB_CASE_LOWER_SIMPLE, MB_CASE_TITLE_SIMPLE, MB_CASE_FOLD_SIMPLE];
for ($cp = 0; $cp <= 0x10FFFF; $cp++) {
    if ($cp >= 0xD800 && $cp <= 0xDFFF) { continue; }
    $u = \mb_chr($cp, 'UTF-8');
    for ($m = 0; $m < 4; $m++) {
        $full = \mb_convert_case($u, $modes[$m], 'UTF-8');
        if ($full !== $u) { $caseFull[$m][$cp] = $full; }
        $simple = \mb_convert_case($u, $simples[$m], 'UTF-8');
        $implied = \mb_strlen($full, 'UTF-8') === 1 ? $full : $u;
        if ($simple !== $implied) { $caseSimple[$m][$cp] = \mb_ord($simple, 'UTF-8'); }
    }
    $afterCased = \substr(\mb_convert_case("x" . $u . "a", MB_CASE_TITLE_SIMPLE, 'UTF-8'), -1);
    $afterPlain = \substr(\mb_convert_case("." . $u . "a", MB_CASE_TITLE_SIMPLE, 'UTF-8'), -1);
    if ($afterCased === "a" && $afterPlain === "A") {
        $ignorable[] = $cp;
        // Zend's look-ahead past its 64-codepoint buffer asks "cased?" BEFORE
        // "ignorable?": a final sigma at index 63 followed by w in the next
        // buffer tells whether an ignorable w is also cased.
        $probe = \mb_strtolower(\str_repeat("a", 63) . "Σ" . $u, 'UTF-8');
        if (\str_contains($probe, "σ")) { $casedIgnorable[] = $cp; }
    }
    if ($afterCased === "a" && $afterPlain === "a") { $cased[] = $cp; }
    if (\mb_strwidth($u, 'UTF-8') === 2) { $wide[] = $cp; }
}
function ranges(array $cps): array
{
    $out = [];
    $n = \count($cps);
    for ($i = 0; $i < $n; $i++) {
        $a = $cps[$i];
        while ($i + 1 < $n && $cps[$i + 1] === $cps[$i] + 1) { $i++; }
        $out[] = $a;
        $out[] = $cps[$i];
    }
    return $out;
}

if (!isset($argv[2]) || !\is_file($argv[1]) || !\is_file($argv[2])) {
    \fwrite(STDERR, "usage: gen_mbstring_tables.php path/to/rare_cp_bitvec.h path/to/html_entities.c\n");
    exit(2);
}
\preg_match_all('/0x([0-9a-fA-F]{8})/', (string)\file_get_contents($argv[1]), $mm);
if (\count($mm[1]) !== 2048) { throw new \RuntimeException("rare_cp_bitvec.h: expected 2048 words"); }
\preg_match_all('/\{"([A-Za-z0-9]+)",\s*(\d+)\}/', (string)\file_get_contents($argv[2]), $em, PREG_SET_ORDER);
$entities = [];
foreach ($em as $m) { $entities[] = [$m[1], (int)$m[2]]; }
if (\count($entities) < 200) { throw new \RuntimeException("html_entities.c: entity list not found"); }
$first = [];
foreach ($entities as [$name, $code]) { if (!isset($first[$code])) { $first[$code] = $name; } }
foreach ($first as $code => $name) {
    if ($code < 0x80) { continue; }
    $got = \mb_convert_encoding(\mb_chr($code, 'UTF-8'), 'HTML-ENTITIES', 'UTF-8');
    if ($got !== "&$name;") { throw new \RuntimeException("html entity $code: Zend says $got, list says &$name;"); }
}
$entityMap = [];
foreach ($entities as [$name, $code]) { if (!isset($entityMap[$name])) { $entityMap[$name] = $code; } }
$rare = '';
foreach ($mm[1] as $word) { $rare .= \bin2hex(\pack('V', \hexdec($word))); }

$php = \PHP_VERSION;
$out = "<?php\n\n// GENERATED by tools/gen_mbstring_tables.php from php $php ext/mbstring — do not edit.\n\n";
$out .= "/**\n * Every encoding mbstring knows, in mb_list_encodings() order.\n * @return string[]\n */\n";
$out .= "function __mc_mb_list(): array\n{\n    return " . export($list) . ";\n}\n\n";
$out .= "/**\n * Aliases per canonical name, as mb_encoding_aliases() answers.\n * @return array<string,string[]>\n */\n";
$out .= "function __mc_mb_alias_table(): array\n{\n    return " . export($aliases) . ";\n}\n\n";
$out .= "/**\n * Preferred MIME name per canonical name, \"\" when there is none.\n * @return array<string,string>\n */\n";
$out .= "function __mc_mb_mime_table(): array\n{\n    return " . export($mime) . ";\n}\n\n";
$out .= "/**\n * Single-byte encodings: codepoint of each byte 0x80..0xFF (-1 = unmapped).\n * The low half is ASCII for all of them (checked at generation).\n * @return array<string,int[]>\n */\n";
$out .= "function __mc_mb_sbcs_tables(): array\n{\n    return " . export($tables) . ";\n}\n";
$out .= "\n/**\n * Single-byte encoders where Zend's byte for a codepoint is not the obvious one\n * (ASCII as itself, else the first byte decoding to it); -1 = not encodable.\n * @return array<string,array<int,int>>\n */\n";
$out .= "function __mc_mb_sbcs_rev_fix(): array\n{\n    return " . export($revFix) . ";\n}\n";
$out .= "\n/**\n * Lead-byte length tables (one digit per byte 0x00..0xFF) of the multibyte\n * encodings Zend splits and cuts by table.\n * @return array<string,string>\n */\n";
$out .= "function __mc_mb_mblen_tables(): array\n{\n    return " . export($mblen) . ";\n}\n";
$names = ['upper', 'lower', 'title', 'fold'];
for ($m = 0; $m < 4; $m++) {
    $out .= "\n/**\n * Full " . $names[$m] . "-case mapping (UTF-8 of the result) of every codepoint it changes.\n * @return array<int,string>\n */\n";
    $out .= "function __mc_mb_case_" . $names[$m] . "(): array\n{\n    return " . export($caseFull[$m]) . ";\n}\n";
    $out .= "\n/**\n * Simple " . $names[$m] . "-case mapping where it is not the full one's single codepoint.\n * @return array<int,int>\n */\n";
    $out .= "function __mc_mb_case_" . $names[$m] . "_simple(): array\n{\n    return " . export($caseSimple[$m]) . ";\n}\n";
}
$out .= "\n/**\n * Case-ignorable codepoints, as [first, last] range pairs.\n * @return int[]\n */\n";
$out .= "function __mc_mb_case_ignorable(): array\n{\n    return " . export(ranges($ignorable)) . ";\n}\n";
$out .= "\n/**\n * Cased codepoints that are not case-ignorable, as [first, last] range pairs.\n * @return int[]\n */\n";
$out .= "function __mc_mb_case_cased(): array\n{\n    return " . export(ranges($cased)) . ";\n}\n";
$out .= "\n/**\n * Case-ignorable codepoints that are also cased, as [first, last] range pairs.\n * @return int[]\n */\n";
$out .= "function __mc_mb_case_cased_ignorable(): array\n{\n    return " . export(ranges($casedIgnorable)) . ";\n}\n";
$out .= "\n/**\n * Codepoints mb_strwidth counts as 2, as [first, last] range pairs.\n * @return int[]\n */\n";
$out .= "function __mc_mb_wide(): array\n{\n    return " . export(ranges($wide)) . ";\n}\n";
$out .= "\n/**\n * HTML-ENTITIES names, in php-src's list order (the encoder takes the first\n * name of a codepoint, the decoder any name).\n * @return array<string,int>\n */\n";
$out .= "function __mc_mb_html_entities(): array\n{\n    return " . export($entityMap) . ";\n}\n";
$out .= "\n/**\n * mb_detect_encoding's 'rare codepoint' bits for U+0000..U+FFFF (php-src\n * rare_cp_bitvec.h), hex of the little-endian words: bit w is byte w>>3, bit w&7.\n */\n";
$out .= "function __mc_mb_rare_hex(): string\n{\n    return '" . $rare . "';\n}\n";
\file_put_contents(__DIR__ . '/../src/Runtime/Stdlib/MbstringTables.php', $out);
echo \count($list), " encodings, ", \count($tables), " single-byte tables\n";

function export(array $a): string
{
    $isList = \array_is_list($a);
    $parts = [];
    foreach ($a as $k => $v) {
        $val = \is_array($v) ? export($v) : (\is_int($v) ? (string)$v : \var_export($v, true));
        $parts[] = $isList ? $val : \var_export($k, true) . ' => ' . $val;
    }
    if ($parts === []) { return '[]'; }
    $flat = \is_int($a[\array_key_first($a)] ?? null);
    return $flat ? '[' . \implode(', ', $parts) . ']' : "[\n        " . \implode(",\n        ", $parts) . ",\n    ]";
}
