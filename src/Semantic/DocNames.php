<?php

namespace Semantic;

use Analyze\DocType;

/**
 * Qualifies the class names inside a docblock type the way PHP resolves a written name: a leading `\` is
 * absolute, the first segment may be a `use` alias, otherwise the name is relative to the current namespace
 * (there is no global fallback for classes). The output spelling is the one native hints arrive in:
 * no leading backslash.
 */
final class DocNames
{
    private const BUILTIN = [
        'int' => 1, 'integer' => 1, 'float' => 1, 'double' => 1, 'string' => 1, 'bool' => 1, 'boolean' => 1,
        'array' => 1, 'list' => 1, 'iterable' => 1, 'callable' => 1, 'object' => 1, 'mixed' => 1, 'void' => 1,
        'never' => 1, 'null' => 1, 'true' => 1, 'false' => 1, 'self' => 1, 'static' => 1, 'parent' => 1,
        'resource' => 1, 'numeric' => 1, 'scalar' => 1,
    ];

    /** A name that is a built-in or pseudo type, never a class (lowercased input). */
    public static function isKeyword(string $lower): bool
    {
        return isset(self::BUILTIN[$lower]);
    }

    /**
     * @param array<string, string> $uses alias → FQN
     * @param string[] $keep names left as written (template parameters)
     */
    public static function qualify(string $type, string $ns, array $uses, array $keep = []): string
    {
        $n = \strlen($type);
        $out = '';
        $i = 0;
        while ($i < $n) {
            $c = $type[$i];
            if ($c === "'" || $c === '"') {
                $e = \strpos($type, $c, $i + 1);
                $e = $e === false ? $n - 1 : $e;
                $out .= \substr($type, $i, $e - $i + 1);
                $i = $e + 1;
                continue;
            }
            if ($c === '$') {
                $j = $i + 1;
                while ($j < $n && self::isIdent($type[$j]) && $type[$j] !== '\\') { $j = $j + 1; }
                $out .= \substr($type, $i, $j - $i);
                $i = $j;
                continue;
            }
            if (!self::isStart($c)) { $out .= $c; $i = $i + 1; continue; }
            $j = $i;
            while ($j < $n && (self::isIdent($type[$j]) || ($type[$j] === '-' && $j + 1 < $n && self::isIdent($type[$j + 1]) && $j > $i))) { $j = $j + 1; }
            $tok = \substr($type, $i, $j - $i);
            $k = $j;
            while ($k < $n && ($type[$k] === ' ' || $type[$k] === "\t")) { $k = $k + 1; }
            $isKey = $k < $n && ($type[$k] === ':' || ($type[$k] === '?' && $k + 1 < $n && $type[$k + 1] === ':'));
            $member = $i >= 2 && $type[$i - 1] === ':' && $type[$i - 2] === ':';
            $out .= $isKey || $member || \in_array($tok, $keep, true) ? $tok : self::name($tok, $ns, $uses);
            $i = $j;
        }
        return $out;
    }

    /**
     * @param array<string, string> $uses
     */
    private static function name(string $tok, string $ns, array $uses): string
    {
        if ($tok[0] === '\\') { return \ltrim($tok, '\\'); }
        if (\strpos($tok, '-') !== false || isset(self::BUILTIN[\strtolower($tok)])) { return $tok; }
        $bs = \strpos($tok, '\\');
        $first = $bs === false ? $tok : \substr($tok, 0, $bs);
        $rest = $bs === false ? '' : \substr($tok, $bs);
        if (\strtolower($first) === 'namespace' && $bs !== false) { return ($ns === '' ? '' : $ns . '\\') . \substr($tok, $bs + 1); }
        foreach ($uses as $alias => $fqn) {
            if (\strtolower((string)$alias) === \strtolower($first)) { return \ltrim($fqn, '\\') . $rest; }
        }
        return $ns === '' ? $tok : $ns . '\\' . $tok;
    }

    private static function isStart(string $c): bool
    {
        return self::isIdent($c) && !($c >= '0' && $c <= '9');
    }

    private static function isIdent(string $c): bool
    {
        return ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || ($c >= '0' && $c <= '9') || $c === '_' || $c === '\\' || \ord($c) >= 128;
    }

    /**
     * Names declared by `@template` tags of a docblock.
     *
     * @return string[]
     */
    public static function templates(?string $doc): array
    {
        if ($doc === null || \strpos($doc, '@') === false) { return []; }
        \preg_match_all('/@(?:phpstan-|psalm-)?template(?:-covariant|-contravariant)?[ \t]+([A-Za-z_][A-Za-z0-9_]*)/', $doc, $m);
        return $m[1];
    }

    /**
     * The qualified type text of a docblock tag, or null when the tag is absent or mentions a template name.
     *
     * @param array<string, string> $uses
     * @param string[] $templates
     */
    public static function tagType(?string $doc, string $tag, string $var, string $ns, array $uses, array $templates): ?string
    {
        $raw = self::rawTag($doc, $tag, $var);
        if ($raw === null || $raw === '') { return null; }
        if (\count($templates) > 0) {
            \preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $raw, $m);
            foreach ($m[0] as $w) { if (\in_array($w, $templates, true)) { return null; } }
        }
        return self::qualify($raw, $ns, $uses);
    }

    /** The tag text with `@phpstan-` then `@psalm-` spellings beating the plain tag. */
    public static function rawTag(?string $doc, string $tag, string $var): ?string
    {
        foreach (['@phpstan-', '@psalm-', '@'] as $pre) {
            $raw = DocType::tag($doc, $pre . \substr($tag, 1), $var);
            if ($raw !== null) { return $raw; }
        }
        return null;
    }

    /**
     * The signature's own `@template` parameters with the qualified param / return texts that use them;
     * null when the docblock declares none. A text that also names a class-level template is dropped.
     *
     * @param \Parser\Ast\Param[] $params
     * @param array<string, string> $uses
     * @param string[] $classTemplates
     */
    public static function templateSig(?string $doc, array $params, string $ns, array $uses, array $classTemplates): ?\Analyze\TemplateSig
    {
        if ($doc === null || \strpos($doc, 'template') === false) { return null; }
        /** @var array<string, string> $tpl */
        $tpl = [];
        foreach (['', 'psalm-', 'phpstan-'] as $pre) {
            \preg_match_all('/@' . $pre . 'template(?:-covariant|-contravariant)?[ \t]+([A-Za-z_][A-Za-z0-9_]*)(?:[ \t]+(?:of|as)[ \t]+([^\s*]+))?/', $doc, $m, \PREG_SET_ORDER);
            foreach ($m as $hit) {
                $tpl[$hit[1]] = isset($hit[2]) ? self::qualify($hit[2], $ns, $uses, \array_keys($tpl)) : '';
            }
        }
        if (\count($tpl) === 0) { return null; }
        $names = \array_keys($tpl);
        $order = [];
        /** @var array<string, string> $ps */
        $ps = [];
        foreach ($params as $p) {
            $order[] = $p->name;
            $raw = self::rawTag($doc, '@param', $p->name);
            $q = self::usable($raw, $names, $classTemplates, $ns, $uses);
            if ($q !== null) { $ps[$p->name] = $q; }
        }
        return new \Analyze\TemplateSig($tpl, $order, $ps, self::usable(self::rawTag($doc, '@return', ''), $names, $classTemplates, $ns, $uses));
    }

    /**
     * @param string[] $names
     * @param string[] $classTemplates
     * @param array<string, string> $uses
     */
    private static function usable(?string $raw, array $names, array $classTemplates, string $ns, array $uses): ?string
    {
        if ($raw === null || $raw === '') { return null; }
        \preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $raw, $m);
        $uses1 = false;
        foreach ($m[0] as $w) {
            if (\in_array($w, $classTemplates, true) && !\in_array($w, $names, true)) { return null; }
            if (\in_array($w, $names, true)) { $uses1 = true; }
        }
        return $uses1 ? self::qualify($raw, $ns, $uses, $names) : null;
    }

    /** FQN's namespace ('' for the global one). */
    public static function nsOf(string $fqn): string
    {
        $bs = \strrpos($fqn, '\\');
        return $bs === false ? '' : \substr($fqn, 0, $bs);
    }
}
