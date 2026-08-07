<?php
/**
 * demangle_stubs.php — turn a generated <prefix>_stubs.c into the PHP-level
 * list of symbols the binary could not resolve.
 *
 * `tools/link_stubs.sh` writes `void* name() { return 0; }` for every undefined
 * symbol at link time. That file is the audit's ground truth for lane (b):
 * it names exactly what the LIVE, post-dead-code-elimination program reaches
 * and cannot find. Strictly stronger than a static report — and strictly
 * blinder, because anything that links is invisible here, bare-alias captures
 * included.
 *
 * Two families are stubbed BY DESIGN and must not be reported:
 *   manticore_rt_*      the runtime-free bootstrap tail (see link_stubs.sh)
 *   manticore_cli_arg*  the entry-point argv shims
 *
 * Usage: php tools/audit/demangle_stubs.php <stubs.c>
 */

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "demangle_stubs: no such file: $path\n");
    exit(2);
}

$src = (string)file_get_contents($path);
preg_match_all('/void\*\s+([A-Za-z_][A-Za-z0-9_.$]*)\s*\(/', $src, $m);

$out = [];
foreach ($m[1] as $sym) {
    // Mach-O prefixes every C symbol with an underscore; ELF does not.
    // link_stubs.sh already strips it when generating, but a hand-run copy may
    // not have, so be tolerant.
    $s = ltrim($sym, '_');
    if (str_starts_with($s, 'manticore_rt_')) { continue; }
    if (str_starts_with($s, 'manticore_cli_arg')) { continue; }
    // `manticore_<name>` is the mangling for a PHP-level function; anything
    // else is a raw C symbol the FFI layer asked for.
    $php = str_starts_with($s, 'manticore_') ? substr($s, 10) : $s;
    $kind = str_starts_with($s, 'manticore_') ? 'php' : 'c';
    $out[$php . "\t" . $kind] = true;
}

$rows = array_keys($out);
sort($rows);
foreach ($rows as $r) { echo $r, "\n"; }
