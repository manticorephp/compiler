<?php

namespace Analyze;

use Parser\Ast\NullLiteral;
use Parser\Ast\Param;
use Parser\Ast\Span;

/**
 * The analyzer's index of the prelude, kept on disk between compiles.
 *
 * The analyzer reads the prelude only as a table of known symbols and
 * signatures, and that table is a pure function of the prelude files: parsing
 * all of them (2.6 MB) on every compile cost ~165 ms of a ~0.5 s compile. The
 * table is stored once per (prelude content, compiler binary, format) and read
 * back in a few milliseconds. Any mismatch is a different key, so a stale entry
 * is never read; any read error is a miss.
 *
 * Only what the rules consult is kept: a parameter's name, hint, whether it has
 * a default (never the default itself), by-ref and variadic.
 */
final class PreludeIndex
{
    /** Bumped whenever the stored shape or what Index::build records changes. */
    private const SCHEMA = 'pi1';

    /** @param array<string, string> $sources prelude file name => contents */
    public static function key(array $sources, string $exeId): string
    {
        $h = '';
        foreach ($sources as $name => $src) {
            $h .= \sha1((string)$name . "\0" . $src, true);
        }
        return \sha1(self::SCHEMA . "\0" . $exeId . "\0" . \sha1($h));
    }

    public static function path(string $key): string
    {
        return \dirname(\Manticore\obj_cache_dir()) . '/analyze/' . $key . '.json';
    }

    public static function load(string $key): ?Index
    {
        try {
            $p = self::path($key);
            if (!\file_exists($p)) {
                return null;
            }
            $txt = \file_get_contents($p);
            if ($txt === false || $txt === '') {
                return null;
            }
            $data = \json_decode($txt, true);
            if (!\is_array($data)) {
                return null;
            }
            return self::fromArray($data);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Best effort: a cache that cannot be written (read-only, full) is just not written. */
    public static function store(string $key, Index $idx): void
    {
        $tmp = '';
        try {
            $p = self::path($key);
            $dir = \dirname($p);
            if (!\is_dir($dir)) {
                \mkdir($dir, 0777, true);
            }
            $txt = \json_encode(self::toArray($idx));
            if (!\is_string($txt)) {
                return;
            }
            $tmp = $p . '.tmp' . (string)\Manticore\getpid() . '_' . \str_replace('.', '', (string)\microtime(true));
            if (\file_put_contents($tmp, $txt) === \strlen($txt) && \rename($tmp, $p)) {
                return;
            }
        } catch (\Throwable $e) {
            // fall through to the cleanup
        }
        try {
            if ($tmp !== '' && \file_exists($tmp)) {
                \unlink($tmp);
            }
        } catch (\Throwable $e) {
            // nothing more to do
        }
    }

    /** @return array<string, mixed> */
    private static function toArray(Index $idx): array
    {
        /** @var array<string, mixed> */
        $fns = [];
        foreach ($idx->functions as $k => $f) {
            $fns[(string)$k] = [$f->name, self::paramsOut($f->params), $f->returnType];
        }
        /** @var array<string, mixed> */
        $cls = [];
        foreach ($idx->classes as $k => $c) {
            /** @var array<string, mixed> */
            $methods = [];
            foreach ($c->methods as $mk => $m) {
                $methods[(string)$mk] = [$m->name, self::paramsOut($m->params), $m->isStatic, $m->visibility,
                    $m->returnType, $m->declClass];
            }
            $cls[(string)$k] = [$c->fqn, $c->kind, $c->parents, $c->interfaces, $c->traits, $methods,
                \array_keys($c->consts), $c->isAbstract, $c->propTypes];
        }
        return ['f' => $fns, 'c' => $cls];
    }

    /**
     * @param Param[] $params
     * @return array<int, mixed>
     */
    private static function paramsOut(array $params): array
    {
        $out = [];
        foreach ($params as $p) {
            $out[] = [$p->name, $p->typeHint, $p->default !== null, $p->byRef, $p->variadic];
        }
        return $out;
    }

    /** @param array<string, mixed> $data */
    private static function fromArray(array $data): ?Index
    {
        $fns = $data['f'] ?? null;
        $cls = $data['c'] ?? null;
        if (!\is_array($fns) || !\is_array($cls)) {
            return null;
        }
        $idx = new Index();
        foreach ($fns as $k => $f) {
            if (!\is_array($f) || \count($f) !== 3 || !\is_array($f[1])) {
                return null;
            }
            $idx->functions[(string)$k] = new FnInfo((string)$f[0], self::paramsIn($f[1]), self::strOrNull($f[2]));
        }
        foreach ($cls as $k => $c) {
            if (!\is_array($c) || \count($c) !== 9 || !\is_array($c[5]) || !\is_array($c[6]) || !\is_array($c[8])) {
                return null;
            }
            /** @var array<string, MethodInfo> */
            $methods = [];
            foreach ($c[5] as $mk => $m) {
                if (!\is_array($m) || \count($m) !== 6 || !\is_array($m[1])) {
                    return null;
                }
                $methods[(string)$mk] = new MethodInfo((string)$m[0], self::paramsIn($m[1]), (bool)$m[2],
                    (string)$m[3], self::strOrNull($m[4]), (string)$m[5]);
            }
            /** @var array<string, bool> */
            $consts = [];
            foreach ($c[6] as $name) { $consts[(string)$name] = true; }
            /** @var array<string, ?string> */
            $propTypes = [];
            foreach ($c[8] as $pn => $ph) { $propTypes[(string)$pn] = self::strOrNull($ph); }
            $idx->classes[(string)$k] = new ClassInfo((string)$c[0], (string)$c[1], self::strings($c[2]),
                self::strings($c[3]), self::strings($c[4]), $methods, $consts, (bool)$c[7], $propTypes);
        }
        return $idx;
    }

    /**
     * @param array<int, mixed> $in
     * @return Param[]
     */
    private static function paramsIn(array $in): array
    {
        $span = new Span(0, 0, '');
        $out = [];
        foreach ($in as $p) {
            if (!\is_array($p) || \count($p) !== 5) {
                continue;
            }
            // Only "has a default" is consulted; the default itself is not kept.
            $out[] = new Param((string)$p[0], self::strOrNull($p[1]), (bool)$p[2] ? new NullLiteral($span) : null,
                (bool)$p[3], (bool)$p[4], '', false, [], $span);
        }
        return $out;
    }

    /** @return string[] */
    private static function strings(mixed $v): array
    {
        $out = [];
        if (\is_array($v)) {
            foreach ($v as $s) { $out[] = (string)$s; }
        }
        return $out;
    }

    private static function strOrNull(mixed $v): ?string
    {
        return $v === null ? null : (string)$v;
    }
}
