<?php
// `require` of a file that is not there throws Error (php 8.5); `include` yields false.
// The target is absent from the build AND from disk.
function attempt(string $kind, string $p): void {
    try {
        $r = $kind === 'require' ? require $p : ($kind === 'require_once' ? require_once $p : include $p);
        echo $kind, ' returned '; var_dump($r);
    } catch (Error $e) {
        echo $kind, ': ', get_class($e), ' | ', substr($e->getMessage(), 0, strpos($e->getMessage(), $p) + 0), "\n";
    }
}
$p = sys_get_temp_dir() . '/__mc_absent_' . getmypid() . '.php';
attempt('require', $p);
attempt('require_once', $p);
echo "include: "; var_dump(@include $p);
try {
    require __DIR__ . '/__mc_absent_constant__.php';
} catch (Error $e) {
    echo get_class($e), ' | ', substr($e->getMessage(), 0, 25), "\n";
}
echo "still running\n";
