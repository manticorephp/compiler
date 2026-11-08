<?php
// A superglobal written on both sides of the module boundary: the library's
// stores and the application's land in ONE slot of one representation.
$_GET = ['app' => 1];
\Acme\Gpc::set('lib', 'x');
echo \Acme\Gpc::keys(), "\n";
for ($i = 0; $i < 3; $i++) {
    \Acme\Gpc::reset($i);
    $_GET['app' . $i] = \str_repeat('a', 64);
    echo implode(',', array_keys($_GET)), "\n";
    $_GET = ['fresh' => $i];
}
echo \Acme\Gpc::keys(), ' ', $_GET['fresh'], "\n";

// Every store releases what it overwrites, on both sides of the boundary.
// memory_get_usage() is peak RSS natively, and a pooled allocator keeps its
// per-class high-water: the first batch may still raise it, a leak raises
// every batch.
function churn(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        \Acme\Gpc::reset($i);
        $_GET['app'] = \str_repeat('a', 200) . $i;
        $_GET = ['fresh' => \str_repeat('f', 200) . $i];
    }
}
/** " RssAnon=… RssFile=…" in kB on Linux, '' elsewhere. */
function rssSplit(): string
{
    $s = is_readable('/proc/self/status') ? (string)file_get_contents('/proc/self/status') : '';
    $o = '';
    foreach (['RssAnon', 'RssFile'] as $k) {
        if (preg_match('/^' . $k . ':\s+(\d+)/m', $s, $m)) { $o .= ' ' . $k . '=' . $m[1]; }
    }
    return $o;
}
$b0 = memory_get_usage();
$r0 = rssSplit();
churn(50000);
$b1 = memory_get_usage();
$r1 = rssSplit();
churn(50000);
$g = memory_get_usage() - $b1;
if ($g < 1024 * 1024) {
    echo "growth ok\n";
} else {
    // ONE line: the gate prints only the tail of this log.
    $big = '';
    $hdr = '';
    foreach (explode("\n", (string)@file_get_contents('/proc/self/smaps')) as $l) {
        if (preg_match('/^[0-9a-f]+-[0-9a-f]+ /', $l)) { $hdr = $l; continue; }
        if (str_starts_with($l, 'Rss:') && (int)trim(substr($l, 4)) >= 512) {
            $p = preg_split('/\s+/', trim($hdr));
            $big .= ' ' . basename((string)($p[5] ?? 'anon')) . '=' . (int)trim(substr($l, 4));
        }
    }
    echo 'growth=', round($g / 1048576, 1), 'MB, first batch ', round(($b1 - $b0) / 1048576, 1), 'MB;',
        ' start', $r0, '; mid', $r1, '; end', rssSplit(), ';', $big, "\n";
}
