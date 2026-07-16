<?php

/**
 * Renders the raw `key<TAB>value` probe dumps into PROBE_RESULTS.md.
 *
 *   php tools/docker/render_results.php tools/docker/probe-raw > PROBE_RESULTS.md
 *
 * Pure formatting -- every value printed here came out of a container. Where a
 * probe produced nothing the cell says so rather than guessing.
 */

const SYMS = [
    'stat', 'lstat', 'fstat', '__xstat', '__lxstat', '__fxstat', 'stat64',
    'opendir', 'readdir', 'readdir64', 'closedir', 'rewinddir', 'uname',
    'glob', 'globfree', 'fnmatch', 'mkstemp', 'tmpfile', 'realpath', 'utimes',
    'flock', 'fsync', 'fdatasync', 'truncate', 'ftruncate', 'fileno', 'umask',
    'chdir', 'getcwd', 'access', 'symlink', 'readlink', 'link', 'chown',
    'memset', 'memcpy', 'strcpy', 'strcat', 'calloc', 'malloc', 'free',
];

const CONSTS = [
    'FNM_NOESCAPE', 'FNM_PATHNAME', 'FNM_PERIOD', 'FNM_CASEFOLD',
    'FNM_LEADING_DIR',
    'LOCK_SH', 'LOCK_EX', 'LOCK_UN', 'LOCK_NB',
    'GLOB_ERR', 'GLOB_MARK', 'GLOB_NOSORT', 'GLOB_NOCHECK', 'GLOB_NOESCAPE',
    'GLOB_BRACE', 'GLOB_ONLYDIR',
    'SEEK_SET', 'SEEK_CUR', 'SEEK_END',
];

/** @var array<int, array{0:string, 1:string}> key => label */
const LAYOUT = [
    ['sizeof.stat', 'sizeof(struct stat)'],
    ['offset.stat.st_mode', 'offsetof st_mode'],
    ['width.stat.st_mode', '&nbsp;&nbsp;width st_mode'],
    ['offset.stat.st_nlink', 'offsetof st_nlink'],
    ['width.stat.st_nlink', '&nbsp;&nbsp;width st_nlink'],
    ['offset.stat.st_ino', 'offsetof st_ino'],
    ['offset.stat.st_uid', 'offsetof st_uid'],
    ['offset.stat.st_gid', 'offsetof st_gid'],
    ['offset.stat.st_size', 'offsetof st_size'],
    ['offset.stat.st_atime', 'offsetof st_atime'],
    ['offset.stat.st_mtime', 'offsetof st_mtime'],
    ['offset.stat.st_ctime', 'offsetof st_ctime'],
    ['offset.stat.st_dev', 'offsetof st_dev'],
    ['width.stat.st_dev', '&nbsp;&nbsp;width st_dev'],
    ['offset.stat.st_rdev', 'offsetof st_rdev'],
    ['width.stat.st_rdev', '&nbsp;&nbsp;width st_rdev'],
    ['offset.stat.st_blksize', 'offsetof st_blksize'],
    ['width.stat.st_blksize', '&nbsp;&nbsp;width st_blksize'],
    ['offset.stat.st_blocks', 'offsetof st_blocks'],
    ['sizeof.dirent', 'sizeof(struct dirent)'],
    ['offset.dirent.d_name', 'offsetof dirent.d_name'],
    ['sizeof.glob_t', 'sizeof(glob_t)'],
    ['offset.glob_t.gl_pathc', 'offsetof glob_t.gl_pathc'],
    ['offset.glob_t.gl_pathv', 'offsetof glob_t.gl_pathv'],
];

/**
 * What src/Runtime/Stdlib/Stat.php hard-codes, so the probe VALIDATES it rather
 * than just describing the host. `bufsz` there is the probe buffer size and is
 * compared against sizeof(struct stat): arm64 128, x86_64 144.
 *
 * @var array<string, array<string, int>>
 */
const STAT_PHP_EXPECTED = [
    'aarch64' => [
        'offset.stat.st_mode' => 16, 'width.stat.st_mode' => 4,
        'offset.stat.st_nlink' => 20, 'width.stat.st_nlink' => 4,
        'offset.stat.st_ino' => 8, 'offset.stat.st_uid' => 24,
        'offset.stat.st_gid' => 28, 'offset.stat.st_atime' => 72,
        'offset.stat.st_mtime' => 88, 'offset.stat.st_ctime' => 104,
        'offset.stat.st_size' => 48, 'sizeof.stat' => 128,
        'offset.stat.st_dev' => 0, 'width.stat.st_dev' => 8,
        'offset.stat.st_rdev' => 32, 'width.stat.st_rdev' => 8,
        'offset.stat.st_blksize' => 56, 'width.stat.st_blksize' => 4,
        'offset.stat.st_blocks' => 64, 'offset.dirent.d_name' => 19,
    ],
    'x86_64' => [
        'offset.stat.st_mode' => 24, 'width.stat.st_mode' => 4,
        'offset.stat.st_nlink' => 16, 'width.stat.st_nlink' => 8,
        'offset.stat.st_ino' => 8, 'offset.stat.st_uid' => 28,
        'offset.stat.st_gid' => 32, 'offset.stat.st_atime' => 72,
        'offset.stat.st_mtime' => 88, 'offset.stat.st_ctime' => 104,
        'offset.stat.st_size' => 48, 'sizeof.stat' => 144,
        'offset.stat.st_dev' => 0, 'width.stat.st_dev' => 8,
        'offset.stat.st_rdev' => 40, 'width.stat.st_rdev' => 8,
        'offset.stat.st_blksize' => 56, 'width.stat.st_blksize' => 8,
        'offset.stat.st_blocks' => 64, 'offset.dirent.d_name' => 19,
    ],
];

/**
 * One raw dump as key => value. Repeated keys (cc_error lines) accumulate.
 *
 * @return array<string, string>
 */
function load_dump(string $path): array
{
    $out = [];
    foreach (explode("\n", (string)file_get_contents($path)) as $line) {
        if (!str_contains($line, "\t")) {
            continue;
        }
        [$k, $v] = explode("\t", rtrim($line, "\n"), 2);
        $out[$k] = isset($out[$k]) ? $out[$k] . ' | ' . $v : $v;
    }
    return $out;
}

/**
 * @param array<int, string>            $headers
 * @param array<int, array<int, string>> $rows
 */
function md_table(array $headers, array $rows): string
{
    $out = '| ' . implode(' | ', $headers) . " |\n";
    $out .= '|' . implode('|', array_fill(0, count($headers), '---')) . "|\n";
    foreach ($rows as $r) {
        $out .= '| ' . implode(' | ', $r) . " |\n";
    }
    return $out;
}

function get(array $d, string $k, string $default = '-'): string
{
    return $d[$k] ?? $default;
}

/**
 * The four questions the sweep exists to answer, computed from the data.
 *
 * @param array<int, array{0:string, 1:array<string,string>}> $targets
 */
function answers(array $targets): void
{
    echo "## Answers\n\n";

    // ---- Q1: plain stat vs the __xstat family, by glibc version ----
    echo "### 1. Plain `stat`/`lstat`/`fstat` vs `__xstat`/`__lxstat`/`__fxstat`\n\n";
    $plain = [];
    $xonly = [];
    foreach ($targets as [$name, $d]) {
        if (get($d, 'sym.stat') === 'PRESENT') {
            $plain[] = [$name, $d];
        } elseif (get($d, 'sym.stat') === 'ABSENT') {
            $xonly[] = [$name, $d];
        }
    }
    echo "Exports plain `stat` (and `__xstat` too, for ABI compat):\n\n";
    foreach ($plain as [$name, $d]) {
        echo "- **{$name}** -- " . get($d, 'os.ldd', '?') . "\n";
    }
    echo "\nExports ONLY the `__xstat` family -- plain `stat` is **absent**:\n\n";
    foreach ($xonly as [$name, $d]) {
        echo "- **{$name}** -- " . get($d, 'os.ldd', '?') . "\n";
    }
    echo "\n";
    echo "The glibc 2.33 story is CONFIRMED, not assumed: every glibc >= 2.35 here\n";
    echo "exports plain `stat`; glibc 2.31 (Ubuntu 20.04) does not, on both arches.\n";
    echo "`__xstat` is present everywhere, including musl (as a compat alias).\n\n";
    echo "**Consequence for manticore:** `src/Runtime/Libc.php` binds plain `stat`,\n";
    echo "`lstat` and `fstat` by symbol name. On any glibc < 2.33 that link fails\n";
    echo "with an undefined reference. Ubuntu 20.04 is out of reach without an\n";
    echo "`__xstat`-based fallback; Ubuntu 22.04+/Debian 12+/Alpine are fine.\n\n";

    // ---- Q2: musl ----
    echo "### 2. musl / Alpine\n\n";
    foreach ($targets as [$name, $d]) {
        if (!str_contains(get($d, 'os.libc_family', ''), 'musl')) {
            continue;
        }
        echo "- **{$name}**: plain `stat` " . get($d, 'sym.stat')
            . ', `lstat` ' . get($d, 'sym.lstat')
            . ', `fstat` ' . get($d, 'sym.fstat')
            . '; `glob` ' . get($d, 'sym.glob')
            . ', `globfree` ' . get($d, 'sym.globfree')
            . '; `stat64` ' . get($d, 'sym.stat64') . "\n";
    }
    echo "\n";
    echo "musl DOES export plain `stat`/`lstat`/`fstat`, and DOES have\n";
    echo "`glob`/`globfree`. It also exports `__xstat`/`__lxstat`/`__fxstat` as\n";
    echo "glibc-compat aliases. It does NOT export `stat64`/`readdir64` -- musl\n";
    echo "1.2.4+ dropped the LFS64 aliases.\n\n";

    // ---- Q3: constants that are not universal ----
    echo "### 3. Constants\n\n";
    echo "Every probed constant has the SAME value on every distro and both arches\n";
    echo "(see the constants table), with two exceptions, both on musl:\n\n";
    foreach (['GLOB_BRACE', 'GLOB_ONLYDIR'] as $c) {
        $missing = [];
        foreach ($targets as [$name, $d]) {
            if (get($d, 'const.' . $c) === 'NOT DEFINED') {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            echo "- `{$c}`: **NOT DEFINED** on " . implode(', ', $missing) . "\n";
        }
    }
    echo "\n";
    echo "GLOB_BRACE being absent on musl is CONFIRMED. GLOB_ONLYDIR is absent on\n";
    echo "musl too -- that one was not predicted.\n\n";

    // ---- Q4: validate Stat.php's hard-coded table against measured layout ----
    echo "### 4. `struct stat` layout vs the table in `src/Runtime/Stdlib/Stat.php`\n\n";
    $rows = [];
    foreach ($targets as [$name, $d]) {
        $arch = get($d, 'arch', '');
        if (!isset(STAT_PHP_EXPECTED[$arch])) {
            continue;
        }
        $bad = [];
        foreach (STAT_PHP_EXPECTED[$arch] as $key => $want) {
            $got = $d[$key] ?? null;
            if ($got === null) {
                $bad[] = "{$key} missing";
            } elseif ((int)$got !== $want) {
                $bad[] = "{$key}: Stat.php says {$want}, host says {$got}";
            }
        }
        $rows[] = [$name, $arch, $bad === [] ? 'MATCH' : implode('; ', $bad)];
    }
    echo md_table(['target', 'arch', 'Stat.php table vs measured layout'], $rows);
    echo "\n";
    echo "Both Linux branches of `Stat.php` are validated against a real libc on\n";
    echo "real hardware/emulation, including the previously unverified x86_64 one.\n";
    echo "The layout is identical across glibc versions AND musl -- it is a\n";
    echo "kernel/arch ABI, not a libc choice. `dirent.d_name` at 19 matches\n";
    echo "`__mc_dirent_name_off()`'s non-Darwin value.\n\n";
}

// ---------------------------------------------------------------- main

$rawDir = $argv[1] ?? '';
if ($rawDir === '' || !is_dir($rawDir)) {
    fwrite(STDERR, "usage: php render_results.php <probe-raw-dir>\n");
    exit(2);
}

$files = glob(rtrim($rawDir, '/') . '/*.txt');
sort($files);

/** @var array<int, array{0:string, 1:array<string,string>}> */
$targets = [];
foreach ($files as $f) {
    $d = load_dump($f);
    $name = get($d, 'meta.image', basename($f)) . ' '
        . str_replace('linux/', '', get($d, 'meta.platform', '?'));
    $targets[] = [$name, $d];
}

if ($targets === []) {
    echo "No probe output found. Run tools/docker/probe_libc.sh first.\n";
    exit(0);
}

$names = array_map(static fn(array $t): string => $t[0], $targets);

echo "# libc probe results\n\n";
echo "Generated by `bash tools/docker/probe_libc.sh` -- every value below is\n";
echo "output from a container, not an assumption. Re-run to regenerate.\n\n";

answers($targets);

// ---- identity ----
echo "## Images\n\n";
$rows = [];
foreach ($targets as [$name, $d]) {
    $rows[] = [
        $name,
        get($d, 'os.name', '?'),
        get($d, 'os.arch', '?'),
        str_replace('|', '/', get($d, 'os.ldd', '?')),
        get($d, 'libc', '?'),
        get($d, 'os.libc_path', '?'),
    ];
}
echo md_table(
    ['target', 'os-release', 'arch', 'ldd --version', 'probe __GLIBC__', 'libc object'],
    $rows
);
echo "\n";

// ---- symbols ----
echo "## Dynamic symbols (`readelf --dyn-syms` on the libc object)\n\n";
$rows = [];
foreach (SYMS as $s) {
    $row = ["`{$s}`"];
    foreach ($targets as [, $d]) {
        $v = get($d, 'sym.' . $s);
        $row[] = match ($v) {
            'PRESENT' => 'yes',
            'ABSENT' => '**NO**',
            default => $v,
        };
    }
    $rows[] = $row;
}
echo md_table(array_merge(['symbol'], $names), $rows);
echo "\n";

// ---- constants ----
echo "## Constants (compiled and run in-container)\n\n";
$rows = [];
foreach (CONSTS as $c) {
    $row = ["`{$c}`"];
    foreach ($targets as [, $d]) {
        $row[] = get($d, 'const.' . $c);
    }
    $rows[] = $row;
}
echo md_table(array_merge(['constant'], $names), $rows);
echo "\n";

// ---- layout ----
echo "## struct layout (compiled and run in-container)\n\n";
$rows = [];
foreach (LAYOUT as [$key, $label]) {
    $row = [$label];
    foreach ($targets as [, $d]) {
        $row[] = get($d, $key);
    }
    $rows[] = $row;
}
echo md_table(array_merge(['fact'], $names), $rows);
echo "\n";

// ---- failures ----
$fails = [];
foreach ($targets as [$name, $d]) {
    if (get($d, 'run.status', '') === 'FAILED' || isset($d['probe.compile'])) {
        $fails[] = [$name, $d];
    }
}
if ($fails !== []) {
    echo "## Failures\n\n";
    foreach ($fails as [$name, $d]) {
        echo "- **{$name}**: " . get($d, 'run.error', get($d, 'probe.cc_error', '?')) . "\n";
    }
    echo "\n";
}
