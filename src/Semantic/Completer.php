<?php

namespace Semantic;

use Analyze\Index;
use Analyze\MemberInfo;

/** Candidate enumeration for completion: members of classes, global symbols. Context detection lives in Workspace. */
final class Completer
{
    public const CAP = 200;

    private const KEYWORDS = ['abstract', 'as', 'break', 'case', 'catch', 'class', 'clone', 'const', 'continue', 'default', 'do', 'echo', 'else', 'elseif', 'enum', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'if', 'implements', 'instanceof', 'interface', 'match', 'namespace', 'new', 'private', 'protected', 'public', 'readonly', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'use', 'while', 'yield'];

    public function __construct(private Index $idx) {}

    /**
     * Members every given class has (union receiver: the union of their members), visible from $from.
     * $static selects `A::` members (static ones, constants, cases; `self`/`parent` also instance methods).
     *
     * @param string[] $classes
     * @return Completion[]
     */
    public function members(array $classes, ?string $from, bool $static, bool $withInstance, string $prefix): array
    {
        /** @var array<string, Completion> $out */
        $out = [];
        foreach ($classes as $cls) {
            foreach ($this->collect($cls) as $m) {
                if (!$this->visible($m, $from) || !self::startsWith($m->name, $prefix)) { continue; }
                $c = self::item($m, $static, $withInstance);
                if ($c !== null) { $c->rank = \strcasecmp(\ltrim($m->declClass, '\\'), \ltrim($cls, '\\')) === 0 ? 0 : 1; $out[$c->kind . ':' . $c->label] = $c; }
            }
        }
        return self::finish($out);
    }

    /** @return Completion[] classes, functions and constants whose name starts with $prefix (case-insensitive) */
    public function symbols(string $prefix): array
    {
        $p = \strtolower(\ltrim($prefix, '\\'));
        /** @var array<string, Completion> $out */
        $out = [];
        foreach ($this->idx->classes as $ci) {
            if (!self::matches($ci->fqn, $p)) { continue; }
            $k = $ci->kind === 'interface' ? 'interface' : ($ci->kind === 'enum' ? 'enum' : 'class');
            $out['c:' . \strtolower($ci->fqn)] = new Completion($ci->fqn, $k);
        }
        foreach ($this->idx->functions as $l => $fn) {
            if (self::matches($fn->name, $p)) { $out['f:' . $l] = new Completion($fn->name, 'function', $fn->returnType ?? ''); }
        }
        foreach ($this->idx->externFunctions as $l => $_) {
            $l = (string)$l;
            if (self::matches($l, $p) && !isset($out['f:' . $l])) { $out['f:' . $l] = new Completion($l, 'function'); }
        }
        foreach ($this->idx->constNames() as $n) {
            if (self::matches($n, $p)) { $out['k:' . $n] = new Completion($n, 'constant'); }
        }
        if ($p !== '' && !\str_contains($p, '\\')) {
            foreach (self::KEYWORDS as $kw) {
                if (\str_starts_with($kw, $p)) { $out['w:' . $kw] = new Completion($kw, 'keyword'); }
            }
        }
        return self::finish($out);
    }

    /** @param array<string, Completion> $items @return Completion[] */
    private static function finish(array $items): array
    {
        $list = \array_values($items);
        \usort($list, fn(Completion $a, Completion $b): int => \strcmp($a->label, $b->label) ?: \strcmp($a->kind, $b->kind));
        return \array_slice($list, 0, self::CAP);
    }

    private static function startsWith(string $name, string $prefix): bool
    {
        return $prefix === '' || \stripos(\ltrim($name, '$'), \ltrim($prefix, '$')) === 0;
    }

    /** Whole name or its last segment starts with the lowercase prefix. */
    private static function matches(string $name, string $lowerPrefix): bool
    {
        $n = \strtolower(\ltrim($name, '\\'));
        if (\str_starts_with($n, $lowerPrefix)) { return true; }
        $bs = \strrpos($n, '\\');
        return $bs !== false && \str_starts_with(\substr($n, $bs + 1), $lowerPrefix);
    }

    private function visible(MemberInfo $m, ?string $from): bool
    {
        if ($m->visibility === 'public') { return true; }
        if ($from === null) { return false; }
        if ($m->visibility === 'private') { return \strcasecmp(\ltrim($from, '\\'), \ltrim($m->declClass, '\\')) === 0; }
        return $this->idx->isSubtype($from, $m->declClass) || $this->idx->isSubtype($m->declClass, $from);
    }

    private static function item(MemberInfo $m, bool $static, bool $withInstance): ?Completion
    {
        if (\str_starts_with($m->name, '__') && !($static && $withInstance && \strcasecmp($m->name, '__construct') === 0)) { return null; }
        $detail = $m->declClass . ($m->typeHint !== null ? ': ' . $m->typeHint : '');
        if ($m->kind === 'case') {
            return $static ? new Completion($m->name, 'enumMember', $detail) : null;
        }
        if ($m->kind === 'const') {
            return $static ? new Completion($m->name, 'constant', $detail) : null;
        }
        if ($m->kind === 'method') {
            if ($static && !$m->isStatic && !$withInstance) { return null; }
            return new Completion($m->name, 'method', $detail);
        }
        if ($m->isStatic !== $static) { return null; }
        $n = $static ? '$' . \ltrim($m->name, '$') : \ltrim($m->name, '$');
        return new Completion($n, 'field', $detail);
    }

    /**
     * Members of the class and everything it inherits, nearest declaration first.
     *
     * @return MemberInfo[]
     */
    private function collect(string $cls): array
    {
        /** @var array<string, MemberInfo> $out */
        $out = [];
        /** @var array<string, bool> $seen */
        $seen = [];
        $this->walk(\strtolower(\ltrim($cls, '\\')), $seen, $out, 0);
        return \array_values($out);
    }

    /**
     * @param array<string, bool> $seen
     * @param array<string, MemberInfo> $out
     */
    private function walk(string $lower, array &$seen, array &$out, int $depth): void
    {
        $ci = $this->idx->classes[$lower] ?? null;
        if ($ci === null || isset($seen[$lower]) || $depth > 50) { return; }
        $seen[$lower] = true;
        foreach ($ci->members as $k => $m) {
            $key = $m->kind === 'case' ? 'const:' . $m->name : $k;
            if (!isset($out[$key])) { $out[$key] = $m; }
        }
        foreach ([$ci->traits, $ci->parents, $ci->interfaces] as $group) {
            foreach ($group as $p) { $this->walk(\strtolower(\ltrim($p, '\\')), $seen, $out, $depth + 1); }
        }
    }
}
