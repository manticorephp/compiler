<?php

namespace Cli\Doctor;

/** The `manticore doctor` probes. A missing or garbled tool is a Result, never a warning or trap. */
final class Checks
{
    private static function which(string $cmd): string
    {
        return \trim((string)\shell_exec('command -v ' . \escapeshellarg($cmd) . ' 2>/dev/null'));
    }

    private static function out(string $cmd): string
    {
        return \trim((string)\shell_exec($cmd . ' 2>/dev/null'));
    }

    private static function installHint(): string
    {
        if (\Manticore\host_os() === 'Darwin') {
            return 'xcode-select --install; brew install pcre2 openssl@3 pkg-config';
        }
        if (\file_exists('/etc/alpine-release')) {
            return 'apk add clang lld gcc musl-dev pcre2-dev openssl-dev zlib-dev pkgconf';
        }
        return 'apt-get install -y gcc libc6-dev libpcre2-dev libssl-dev zlib1g-dev pkg-config clang-NN (>= 15)';
    }

    public static function compiler(): Result
    {
        return new Result(Result::OK, 'manticore ' . \Manticore\Version::STRING, \Manticore\self_dir());
    }

    public static function install(): Result
    {
        if (\Cli\Install::installer() !== '') {
            return new Result(Result::OK, 'install', 'installed by install.sh');
        }
        return new Result(Result::WARN, 'install', \Cli\Install::owner(\Manticore\self_dir()), 'update it with that installer');
    }

    public static function clang(): Result
    {
        if (self::which('clang') === '') {
            return new Result(Result::FAIL, 'clang', 'not found', self::installHint());
        }
        $text = self::out('clang --version');
        $nl = \strpos($text, "\n");
        $line = $nl === false ? $text : \substr($text, 0, $nl);
        $m = [];
        if (\preg_match('/version (\d+)/', $line, $m) !== 1) {
            return new Result(Result::FAIL, 'clang', 'cannot parse `' . $line . '`', 'need clang >= 15');
        }
        if ((int)$m[1] < 15) {
            return new Result(Result::FAIL, 'clang', $line . ': needs >= 15 (opaque-pointer IR)', 'install clang >= 15');
        }
        return new Result(Result::OK, 'clang', $line);
    }

    public static function cc(): Result
    {
        $path = self::which('cc');
        if ($path === '') {
            return new Result(Result::FAIL, 'cc', 'not found', self::installHint());
        }
        return new Result(Result::OK, 'cc', $path);
    }

    /** @return Result[] */
    public static function assets(): array
    {
        $fix = 'reinstall: install.sh / upgrade';
        $items = [
            'stdlib object' => \Manticore\find_stdlib_object(),
            'stdlib signature' => \Manticore\find_stdlib_sig(),
            'prelude' => \Manticore\find_prelude_src('array_fns.php') === '' ? '' : 'found',
        ];
        $res = [];
        foreach ($items as $name => $path) {
            $res[] = $path === ''
                ? new Result(Result::FAIL, $name, 'not found', $fix)
                : new Result(Result::OK, $name, $path);
        }
        return $res;
    }

    /** @return Result[] */
    public static function libs(): array
    {
        $hint = self::installHint();
        $res = [];
        $v = self::out('pcre2-config --version');
        $res[] = $v !== '' ? new Result(Result::OK, 'pcre2', $v) : new Result(Result::WARN, 'pcre2', 'not found', $hint);
        if (self::which('pkg-config') === '') {
            $res[] = new Result(Result::WARN, 'pkg-config', 'pkg-config not found: cannot probe openssl/zlib', $hint);
            return $res;
        }
        foreach (['openssl', 'zlib'] as $lib) {
            $v = self::out('pkg-config --modversion ' . $lib);
            $res[] = $v !== '' ? new Result(Result::OK, $lib, $v) : new Result(Result::WARN, $lib, 'not found', $hint);
        }
        return $res;
    }

    public static function smoke(): Result
    {
        $exe = \Manticore\self_dir() . '/manticore';
        if (!\is_executable($exe)) {
            return new Result(Result::FAIL, 'smoke test', 'cannot locate own executable');
        }
        $dir = \trim((string)\shell_exec('mktemp -d 2>/dev/null'));
        if ($dir === '') {
            return new Result(Result::FAIL, 'smoke test', 'cannot create a temp dir');
        }
        \file_put_contents($dir . '/hello.php', "<?php echo \"ok\\n\";\n");
        $d = \escapeshellarg($dir);
        $log = (string)\shell_exec('cd ' . $d . ' && ' . \escapeshellarg($exe) . ' compile hello.php -o hello 2>&1');
        $run = \trim((string)\shell_exec('cd ' . $d . ' && ./hello 2>&1'));
        \shell_exec('rm -rf ' . $d);
        if ($run === 'ok') {
            return new Result(Result::OK, 'smoke test', 'compiled and ran hello.php');
        }
        $lines = \explode("\n", \trim($log));
        $tail = \implode("\n", \array_slice($lines, -3));
        return new Result(Result::FAIL, 'smoke test', 'compile or run failed: ' . $tail);
    }

    public static function update(bool $offline): Result
    {
        if ($offline) {
            return new Result(Result::OK, 'update', 'skipped (--offline)');
        }
        if (\Cli\Install::installer() === '') {
            return new Result(Result::OK, 'update', 'skipped (not managed by install.sh)');
        }
        $latest = \Cli\Install::latest('doctor', '', true);
        if ($latest === '') {
            return new Result(Result::WARN, 'update', 'could not check for updates');
        }
        $installed = \Manticore\Version::STRING;
        if (\version_compare($installed, $latest) < 0) {
            return new Result(Result::WARN, 'update', 'update available: ' . $installed . ' -> ' . $latest, 'run: manticore upgrade');
        }
        return new Result(Result::OK, 'update', 'up to date (' . $installed . ')');
    }
}
