<?php

declare(strict_types=1);

namespace Compile\Mir\Passes;

use Compile\Mir\DeclMember;

/**
 * Inheritance compatibility of declared types, mixed into `LowerFromAst`
 * next to LowerClassDeclChecks: method signatures (LSP), property types,
 * typed class constants.
 *
 * Only native declarations are read (parameter, return, property and constant
 * type hints). A type that cannot be resolved exactly — unknown class, prelude
 * or library ancestry, superset syntax — relates as COMPATIBLE: a false
 * positive refuses a program php accepts, a false negative only misses a fatal.
 * Prelude and imported library parents are never checked: their signatures need
 * not mirror php's.
 *
 * A type is a list of members: builtin atoms lower-cased, class names with their
 * declared case, and `A&B` strings for an intersection group.
 */
trait LowerSigCompat
{
    /** @var array<string, bool> */
    private array $preludeClassNames = [];

    /** @var array<string, string> */
    private array $lspLower = [];

    /** @param \Parser\Ast\Stmt[] $stmts */
    private function markPreludeClasses(array $stmts, int $preludeCount): void
    {
        $i = -1;
        foreach ($stmts as $stmt) {
            $i = $i + 1;
            if ($i >= $preludeCount) { break; }
            if ($stmt->kind === 'Class') {
                $this->preludeClassNames[$this->classDeclName($this->classStmtDecl($stmt))] = true;
            }
        }
    }

    private function lspSkipClass(string $name): bool
    {
        return isset($this->preludeClassNames[$name]) || isset($this->externClassNames[$name]);
    }

    private function lspRealName(string $n): string
    {
        $n = \ltrim($n, '\\');
        if (isset($this->classDecls[$n]) || isset($this->traitTable[$n])) { return $n; }
        if ($this->lspLower === []) {
            foreach ($this->classDecls as $k => $_) { $this->lspLower[\strtolower((string)$k)] = (string)$k; }
            foreach ($this->traitTable as $k => $_) { $this->lspLower[\strtolower((string)$k)] = (string)$k; }
        }
        return $this->lspLower[\strtolower($n)] ?? $n;
    }

    private function lspDeclOf(string $name): ?\Parser\Ast\ClassDecl
    {
        $d = $this->classDecls[$name] ?? ($this->traitTable[$name] ?? null);
        return $d === null ? null : $this->classDeclOf($d);
    }

    private function lspIsBuiltin(string $low): bool
    {
        return $low === 'int' || $low === 'float' || $low === 'string' || $low === 'bool'
            || $low === 'false' || $low === 'true' || $low === 'null' || $low === 'array'
            || $low === 'callable' || $low === 'object' || $low === 'mixed' || $low === 'void'
            || $low === 'never' || $low === 'static';
    }

    /**
     * @return string[]|null members, or null when the hint cannot be resolved exactly
     */
    private function lspParseType(string $hint, string $scope, bool $nullDefault): ?array
    {
        if (\str_contains($hint, '<') || \str_contains($hint, '[')) { return null; }
        $nullable = false;
        if (\str_starts_with($hint, '?')) {
            $nullable = true;
            $hint = \substr($hint, 1);
        }
        $members = [];
        $depth = 0;
        $cur = '';
        $n = \strlen($hint);
        $i = 0;
        while ($i <= $n) {
            $ch = $i < $n ? $hint[$i] : '|';
            if ($ch === '(') { $depth = $depth + 1; }
            if ($ch === ')') { $depth = $depth - 1; }
            if ($ch === '|' && $depth === 0) {
                if (!$this->lspParseMember($cur, $scope, $members)) { return null; }
                $cur = '';
            } else {
                $cur = $cur . $ch;
            }
            $i = $i + 1;
        }
        $hasNull = false;
        foreach ($members as $m) {
            if ($m === 'null' || $m === 'mixed') { $hasNull = true; }
        }
        if (($nullable || $nullDefault) && !$hasNull) {
            $members[] = 'null';
        }
        return $members;
    }

    /**
     * @param string[] $out
     */
    private function lspParseMember(string $part, string $scope, array &$out): bool
    {
        if (\str_starts_with($part, '(') && \str_ends_with($part, ')')) {
            $part = \substr($part, 1, \strlen($part) - 2);
        }
        if (\strtolower($part) === 'iterable') {
            $out[] = 'Traversable';
            $out[] = 'array';
            return true;
        }
        $atoms = [];
        foreach (\explode('&', $part) as $a) {
            $r = $this->lspAtom($a, $scope);
            if ($r === null) { return false; }
            foreach ($r as $x) { $atoms[] = $x; }
        }
        if (\count($atoms) === 1) {
            $out[] = $atoms[0];
        } else {
            $out[] = \implode('&', $atoms);
        }
        return true;
    }

    /** @return string[]|null */
    private function lspAtom(string $a, string $scope): ?array
    {
        if ($a === '') { return null; }
        $low = \strtolower($a);
        if ($low === 'iterable') { return ['Traversable', 'array']; }
        if ($this->lspIsBuiltin($low)) { return [$low]; }
        if ($low === 'self') { return $scope === '' ? null : [$scope]; }
        if ($low === 'parent') {
            $d = $scope === '' ? null : $this->lspDeclOf($scope);
            if ($d === null || $d->extends === [] || $d->kind !== 'class') { return null; }
            return [$this->lspRealName($d->extends[0])];
        }
        if ($low === 'numeric' || $low === 'integer' || $low === 'boolean' || $low === 'double') { return null; }
        return [$this->lspRealName($a)];
    }

    /** @param string[] $members */
    private function lspRender(array $members): string
    {
        $classes = [];
        $static = false;
        $callable = false;
        $object = false;
        $array = false;
        $string = false;
        $int = false;
        $float = false;
        $bool = false;
        $false = false;
        $true = false;
        $void = false;
        $never = false;
        $null = false;
        $mixed = false;
        foreach ($members as $m) {
            if (\str_contains($m, '&')) { $classes[] = '(' . $m . ')'; continue; }
            $low = \strtolower($m);
            if (!$this->lspIsBuiltin($low)) { $classes[] = $m; continue; }
            if ($low === 'static') { $static = true; }
            elseif ($low === 'callable') { $callable = true; }
            elseif ($low === 'object') { $object = true; }
            elseif ($low === 'array') { $array = true; }
            elseif ($low === 'string') { $string = true; }
            elseif ($low === 'int') { $int = true; }
            elseif ($low === 'float') { $float = true; }
            elseif ($low === 'bool') { $bool = true; }
            elseif ($low === 'false') { $false = true; }
            elseif ($low === 'true') { $true = true; }
            elseif ($low === 'void') { $void = true; }
            elseif ($low === 'never') { $never = true; }
            elseif ($low === 'null') { $null = true; }
            else { $mixed = true; }
        }
        if ($mixed) { return 'mixed'; }
        $parts = $classes;
        if ($static) { $parts[] = 'static'; }
        if ($callable) { $parts[] = 'callable'; }
        if ($object) { $parts[] = 'object'; }
        if ($array) { $parts[] = 'array'; }
        if ($string) { $parts[] = 'string'; }
        if ($int) { $parts[] = 'int'; }
        if ($float) { $parts[] = 'float'; }
        if ($bool || ($false && $true)) { $parts[] = 'bool'; }
        elseif ($false) { $parts[] = 'false'; }
        elseif ($true) { $parts[] = 'true'; }
        if ($void) { $parts[] = 'void'; }
        if ($never) { $parts[] = 'never'; }
        if (!$null) {
            if (\count($parts) === 1 && \str_starts_with($parts[0], '(')) {
                return \substr($parts[0], 1, \strlen($parts[0]) - 2);
            }
            return \implode('|', $parts);
        }
        if ($parts === []) { return 'null'; }
        if (\count($parts) === 1 && !\str_starts_with($parts[0], '(')) { return '?' . $parts[0]; }
        return \implode('|', $parts) . '|null';
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    private function lspSub(array $a, string $scopeA, array $b): bool
    {
        $aLow = [];
        foreach ($a as $m) { $aLow[] = \strtolower($m); }
        $bLow = [];
        foreach ($b as $m) { $bLow[] = \strtolower($m); }
        if (\in_array('void', $aLow, true)) { return \in_array('void', $bLow, true); }
        if (\in_array('mixed', $bLow, true)) { return true; }
        if (\in_array('mixed', $aLow, true)) { return false; }
        foreach ($a as $m) {
            if (\strtolower($m) === 'never') { continue; }
            if (!$this->lspMemberSub($m, $scopeA, $b, $bLow)) { return false; }
        }
        return true;
    }

    /**
     * @param string[] $b
     * @param string[] $bLow
     */
    private function lspMemberSub(string $m, string $scopeA, array $b, array $bLow): bool
    {
        if ($m === 'bool' && \in_array('false', $bLow, true) && \in_array('true', $bLow, true)) { return true; }
        $aParts = \explode('&', $m);
        foreach ($b as $bm) {
            $ok = true;
            foreach (\explode('&', $bm) as $bp) {
                $any = false;
                foreach ($aParts as $ap) {
                    if ($this->lspAtomSub($ap, $scopeA, $bp)) { $any = true; break; }
                }
                if (!$any) { $ok = false; break; }
            }
            if ($ok) { return true; }
        }
        return false;
    }

    private function lspAtomSub(string $a, string $scopeA, string $b): bool
    {
        $al = \strtolower($a);
        $bl = \strtolower($b);
        if ($al === $bl) { return true; }
        $aBuiltin = $this->lspIsBuiltin($al);
        $bBuiltin = $this->lspIsBuiltin($bl);
        if ($al === 'false' || $al === 'true') { return $bl === 'bool'; }
        if ($al === 'static') {
            if ($bl === 'object') { return true; }
            return !$bBuiltin && $this->lspClassIsA($scopeA, $b);
        }
        if ($aBuiltin) { return false; }
        if ($bl === 'object') { return true; }
        if ($bl === 'callable') { return $al === 'closure'; }
        if ($bBuiltin) { return false; }
        return $this->lspClassIsA($a, $b);
    }

    /** True unless `$a` is provably not `$b` or a descendant (unknown relates as true). */
    private function lspClassIsA(string $a, string $b): bool
    {
        $a = $this->lspRealName($a);
        $b = $this->lspRealName($b);
        if (\strtolower($a) === \strtolower($b)) { return true; }
        $bl = \strtolower($b);
        if ($bl === 'stringable' || $bl === 'unitenum' || $bl === 'backedenum') { return true; }
        if ($this->lspSkipClass($a)) { return true; }
        if ($this->lspDeclOf($a) === null || $this->lspDeclOf($b) === null) { return true; }
        $seen = [];
        $queue = [$a];
        $qi = 0;
        while ($qi < \count($queue)) {
            $cur = $queue[$qi];
            $qi = $qi + 1;
            if (isset($seen[\strtolower($cur)])) { continue; }
            $seen[\strtolower($cur)] = true;
            if (\strtolower($cur) === $bl) { return true; }
            if ($this->lspSkipClass($cur)) { return true; }
            $d = $this->lspDeclOf($cur);
            if ($d === null) { return true; }
            foreach ($d->extends as $x) { $queue[] = $this->lspRealName($x); }
            foreach ($d->implements as $x) { $queue[] = $this->lspRealName($x); }
        }
        return false;
    }

    private function checkSigCompat(string $cls, \Parser\Ast\MethodDecl $c, DeclMember $inh, \Parser\Ast\Span $span): void
    {
        $p = $inh->decl;
        if ($p === null || $this->lspSkipClass($inh->declClass)) { return; }
        if (!$inh->isAbstract && ($c->isAbstract || \strtolower($c->name) === '__construct')) { return; }
        $pcls = $inh->declClass;
        if ($this->lspSigOk($cls, $c, $pcls, $p)) { return; }
        $this->declFail('Declaration of ' . $this->lspSigText($cls, $c) . ' must be compatible with '
            . $this->lspSigText($pcls, $p), $span);
    }

    private function lspRequired(\Parser\Ast\MethodDecl $m): int
    {
        $req = 0;
        $i = 0;
        foreach ($m->params as $par) {
            $i = $i + 1;
            if ($par->default === null && !$par->variadic) { $req = $i; }
        }
        return $req;
    }

    private function lspParamType(\Parser\Ast\Param $par, string $scope): ?array
    {
        $h = $par->typeHint;
        if ($h === null) { return ['mixed']; }
        $nullDef = $par->default !== null && $this->lspIsNullDefault($par->default);
        return $this->lspParseType($h, $scope, $nullDef);
    }

    private function lspIsNullDefault(\Parser\Ast\Expr $e): bool
    {
        return $e->kind === 'NullLiteral';
    }

    private function lspSigOk(string $cls, \Parser\Ast\MethodDecl $c, string $pcls, \Parser\Ast\MethodDecl $p): bool
    {
        $cp = $c->params;
        $pp = $p->params;
        if ($this->lspRequired($c) > $this->lspRequired($p)) { return false; }
        $cn = \count($cp);
        $pn = \count($pp);
        $cVar = $cn > 0 && $cp[$cn - 1]->variadic;
        $pVar = $pn > 0 && $pp[$pn - 1]->variadic;
        $cFixed = $cVar ? $cn - 1 : $cn;
        $pFixed = $pVar ? $pn - 1 : $pn;
        if ($pFixed > $cFixed && !$cVar) { return false; }
        if ($pVar && !$cVar) { return false; }
        if ($p->returnsByRef && !$c->returnsByRef) { return false; }
        for ($i = 0; $i < $pn; $i = $i + 1) {
            $pa = $pp[$i];
            $ca = $i < $cFixed ? $cp[$i] : ($cVar ? $cp[$cn - 1] : null);
            if ($ca === null) { return false; }
            if ($ca->byRef !== $pa->byRef) { return false; }
            if ($ca->typeHint === null) { continue; }
            $ct = $this->lspParamType($ca, $cls);
            $pt = $this->lspParamType($pa, $pcls);
            if ($ct === null || $pt === null) { continue; }
            if (!$this->lspSub($pt, $pcls, $ct)) { return false; }
        }
        $pr = $p->returnType;
        if ($pr === null) { return true; }
        $cr = $c->returnType;
        if ($cr === null) { return false; }
        $ct = $this->lspParseType($cr, $cls, false);
        $pt = $this->lspParseType($pr, $pcls, false);
        if ($ct === null || $pt === null) { return true; }
        return $this->lspSub($ct, $cls, $pt);
    }

    private function lspSigText(string $cls, \Parser\Ast\MethodDecl $m): string
    {
        $req = $this->lspRequired($m);
        $s = ($m->returnsByRef ? '& ' : '') . $cls . '::' . $m->name . '(';
        $i = 0;
        foreach ($m->params as $par) {
            if ($i > 0) { $s = $s . ', '; }
            $h = $par->typeHint;
            if ($h !== null) {
                $nullDef = $par->default !== null && $this->lspIsNullDefault($par->default);
                $t = $this->lspParseType($h, $cls, $nullDef);
                $s = $s . ($t === null ? $h : $this->lspRender($t)) . ' ';
            }
            if ($par->byRef) { $s = $s . '&'; }
            if ($par->variadic) { $s = $s . '...'; }
            $s = $s . '$' . $par->name;
            if ($i >= $req && !$par->variadic && $par->default !== null) {
                $s = $s . ' = ' . $this->lspDefaultText($par->default);
            }
            $i = $i + 1;
        }
        $s = $s . ')';
        $r = $m->returnType;
        if ($r !== null) {
            $t = $this->lspParseType($r, $cls, false);
            $s = $s . ': ' . ($t === null ? $r : $this->lspRender($t));
        }
        return $s;
    }

    private function lspDefaultText(\Parser\Ast\Expr $e): string
    {
        $k = $e->kind;
        if ($k === 'IntLiteral') { return (string)$this->lspInt($e); }
        if ($k === 'FloatLiteral') { return (string)$this->lspFloat($e); }
        if ($k === 'StringLiteral') {
            $v = $this->lspStr($e);
            return "'" . (\strlen($v) > 10 ? \substr($v, 0, 10) . '...' : $v) . "'";
        }
        if ($k === 'BoolLiteral') { return $this->lspBool($e) ? 'true' : 'false'; }
        if ($k === 'NullLiteral') { return 'null'; }
        if ($k === 'ArrayLit') { return $this->lspElemCount($e) === 0 ? '[]' : '[...]'; }
        if ($k === 'Identifier') { return \ltrim($this->lspIdent($e), '\\'); }
        if ($k === 'StaticAccess') {
            $cn = $this->lspAccessClass($e);
            $nm = $this->lspAccessName($e);
            $lc = \strtolower($cn);
            if ($nm === 'class' && $lc !== 'self' && $lc !== 'static' && $lc !== 'parent') { return "'" . $cn . "'"; }
            return $cn . '::' . $nm;
        }
        if ($k === 'UnaryOp' && $this->lspUnaryOp($e) === '-') {
            $o = $this->lspUnaryOperand($e);
            if ($o->kind === 'IntLiteral' || $o->kind === 'FloatLiteral') { return '-' . $this->lspDefaultText($o); }
        }
        return '<expression>';
    }

    private function lspInt(\Parser\Ast\IntLiteral $e): int { return $e->value; }
    private function lspFloat(\Parser\Ast\FloatLiteral $e): float { return $e->value; }
    private function lspStr(\Parser\Ast\StringLiteral $e): string { return $e->value; }
    private function lspBool(\Parser\Ast\BoolLiteral $e): bool { return $e->value; }
    private function lspIdent(\Parser\Ast\Identifier $e): string { return $e->name; }
    private function lspElemCount(\Parser\Ast\ArrayLit $e): int { return \count($e->elements); }
    private function lspAccessClass(\Parser\Ast\StaticAccess $e): string { return $e->class; }
    private function lspAccessName(\Parser\Ast\StaticAccess $e): string { return $e->name; }
    private function lspUnaryOp(\Parser\Ast\UnaryOp $e): string { return $e->op; }
    private function lspUnaryOperand(\Parser\Ast\UnaryOp $e): \Parser\Ast\Expr { return $e->operand; }

    private function checkPropCompat(string $cls, string $name, bool $isStatic, bool $isReadonly,
                                     DeclMember $inh, \Parser\Ast\Span $span): bool
    {
        $what = '$' . $name;
        if ($inh->isStatic !== $isStatic) {
            $this->declFail('Cannot redeclare ' . ($inh->isStatic ? 'static' : 'non static') . ' '
                . $inh->declClass . '::' . $what . ' as ' . ($isStatic ? 'static' : 'non static') . ' '
                . $cls . '::' . $what, $span);
            return false;
        }
        if ($this->lspSkipClass($inh->declClass)) { return true; }
        if ($inh->isReadonly !== $isReadonly) {
            $this->declFail('Cannot redeclare ' . ($inh->isReadonly ? 'readonly' : 'non-readonly') . ' property '
                . $inh->declClass . '::' . $what . ' as ' . ($isReadonly ? 'readonly' : 'non-readonly') . ' '
                . $cls . '::' . $what, $span);
            return false;
        }
        return true;
    }

    private function checkPropType(string $cls, string $name, ?string $type, bool $nullDefault, DeclMember $inh,
                                   \Parser\Ast\Span $span): void
    {
        if ($this->lspSkipClass($inh->declClass)) { return; }
        $pt = $inh->type === null ? null : $this->lspParseType($inh->type, $inh->declClass, false);
        if ($inh->type !== null && $pt === null) { return; }
        $ct = $type === null ? null : $this->lspParseType($type, $cls, $nullDefault);
        if ($type !== null && $ct === null) { return; }
        if ($pt === null && $ct === null) { return; }
        if ($pt === null) {
            $this->declFail('Type of ' . $cls . '::$' . $name . ' must be omitted to match the parent definition in class '
                . $inh->declClass, $span);
            return;
        }
        if ($ct !== null && $this->lspSub($ct, $cls, $pt) && $this->lspSub($pt, $inh->declClass, $ct)) { return; }
        $this->declFail('Type of ' . $cls . '::$' . $name . ' must be ' . $this->lspRender($pt)
            . ' (as in class ' . $inh->declClass . ')', $span);
    }

    private function checkConstType(string $cls, \Parser\Ast\ConstDecl $c, DeclMember $inh, \Parser\Ast\Span $span): void
    {
        if ($inh->type === null || $this->lspSkipClass($inh->declClass)) { return; }
        $pt = $this->lspParseType($inh->type, $inh->declClass, false);
        if ($pt === null) { return; }
        $h = $c->typeHint;
        $ct = $h === null ? null : $this->lspParseType($h, $cls, false);
        if ($h !== null && $ct === null) { return; }
        if ($ct !== null && $this->lspSub($ct, $cls, $pt)) { return; }
        $this->declFail('Type of ' . $cls . '::' . $c->name . ' must be compatible with '
            . $inh->declClass . '::' . $inh->name . ' of type ' . $this->lspRender($pt), $span);
    }
}
