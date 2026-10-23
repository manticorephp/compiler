<?php

declare(strict_types=1);

namespace Compile\Mir\Passes;

use Compile\Mir\DeclMember;
use Compile\Mir\DeclTable;

/**
 * The class-declaration contracts PHP enforces when it links a class, mixed into
 * `LowerFromAst`: extending a final class, overriding a final method or
 * constant, leaving an abstract method unimplemented, weakening a member's
 * visibility, changing a method's static-ness.
 *
 * Runs on the AST right after the decl tables are complete (ClassDef carries
 * neither constant modifiers nor source lines). Covers prelude and user classes
 * alike. A class whose ancestry includes a type with no declaration here (a
 * library class) is checked only for the rules that need no further ancestor.
 * Signature (LSP) compatibility is NOT checked.
 */
trait LowerClassDeclChecks
{
    /** @var array<string, DeclTable> */
    private array $declTables = [];

    /** @var array<string, bool> */
    private array $declTableBusy = [];

    private function checkClassDecls(): void
    {
        foreach ($this->classDecls as $name => $d0) {
            $d = $this->classDeclOf($d0);
            $n = \ltrim((string)$name, '\\');
            if (isset($this->externClassNames[$n])) { continue; }
            $this->checkClassDecl($d, $n);
        }
    }

    private function checkClassDecl(\Parser\Ast\ClassDecl $d, string $cls): void
    {
        $kind = $d->kind;
        if ($kind !== 'class' && $kind !== 'enum') { return; }
        $span = $d->span;
        $line = $span->line;
        $parentTable = null;
        if ($kind === 'class' && $d->extends !== []) {
            $pn = \ltrim($d->extends[0], '\\');
            $pd = $this->classDecls[$pn] ?? ($this->traitTable[$pn] ?? null);
            if ($pd !== null) {
                $pd = $this->classDeclOf($pd);
                $pk = $pd->kind;
                if ($pk === 'interface' || $pk === 'trait' || $pk === 'enum') {
                    $this->declFail('Class ' . $cls . ' cannot extend ' . $pk . ' ' . $pn, $span);
                } elseif ($pd->isFinal) {
                    $this->declFail('Class ' . $cls . ' cannot extend final class ' . $pn, $span);
                }
                $parentTable = $this->declTableOf($pn);
            }
        }
        $ifaceTables = [];
        foreach ($d->implements as $in) {
            $iname = \ltrim($in, '\\');
            $idecl = $this->classDecls[$iname] ?? null;
            if ($idecl === null) { continue; }
            if ($this->classDeclOf($idecl)->kind !== 'interface') {
                $this->declFail($cls . ' cannot implement ' . $iname . ' - it is not an interface', $span);
            }
            $it = $this->declTableOf($iname);
            if ($it !== null) { $ifaceTables[] = $it; }
        }

        foreach ($d->methods as $m) {
            $this->checkOwnMethod($d, $cls, $m, $parentTable, $ifaceTables);
        }
        foreach ($d->consts as $c) {
            $this->checkOwnConst($cls, $c, $parentTable, $ifaceTables, $span);
        }
        $this->checkOwnProps($d, $cls, $parentTable, $span);

        $table = $this->declTableOf($cls);
        if ($table === null || !$table->complete || $d->isAbstract) { return; }
        $missing = [];
        foreach ($table->methods as $mem) {
            if ($mem->isAbstract) { $missing[] = $mem->declClass . '::' . $mem->name; }
        }
        $cnt = \count($missing);
        if ($cnt === 0) { return; }
        $shown = \implode(', ', \array_slice($missing, 0, 3));
        if ($cnt > 3) { $shown = $shown . ', ...'; }
        $plural = $cnt === 1 ? '' : 's';
        if ($kind === 'enum') {
            $this->declFail('Enum ' . $cls . ' must implement ' . (string)$cnt . ' abstract method'
                . $plural . ' (' . $shown . ')', $span);
            return;
        }
        $this->declFail('Class ' . $cls . ' contains ' . (string)$cnt . ' abstract method' . $plural
            . ' and must therefore be declared abstract or implement the remaining method' . $plural
            . ' (' . $shown . ')', $span);
    }

    /** @param DeclTable[] $ifaceTables */
    private function checkOwnMethod(\Parser\Ast\ClassDecl $d, string $cls, \Parser\Ast\MethodDecl $m,
                                    ?DeclTable $parentTable, array $ifaceTables): void
    {
        $mname = $m->name;
        $mspan = $m->span;
        $vis = $m->visibility === '' ? 'public' : $m->visibility;
        if ($m->isAbstract) {
            if ($m->isFinal) {
                $this->declFail('Cannot use the final modifier on an abstract method', $mspan);
            }
            if ($vis === 'private' && $d->kind !== 'trait') {
                $this->declFail('Abstract function ' . $cls . '::' . $mname . '() cannot be declared private', $mspan);
            }
            if ($d->kind === 'class' && !$d->isAbstract) {
                $this->declFail('Class ' . $cls . ' declares abstract method ' . $mname
                    . '() and must therefore be declared abstract', $mspan);
            }
        }
        $key = \strtolower($mname);
        $inh = $parentTable !== null ? ($parentTable->methods[$key] ?? null) : null;
        if ($inh === null) {
            foreach ($ifaceTables as $it) {
                $inh = $it->methods[$key] ?? null;
                if ($inh !== null) { break; }
            }
        }
        if ($inh === null) { return; }
        if ($inh->isFinal) {
            $this->declFail('Cannot override final method ' . $inh->declClass . '::' . $inh->name . '()', $mspan);
        }
        if ($m->isStatic !== $inh->isStatic) {
            $this->declFail($inh->isStatic
                ? 'Cannot make static method ' . $inh->declClass . '::' . $inh->name . '() non static in class ' . $cls
                : 'Cannot make non static method ' . $inh->declClass . '::' . $inh->name . '() static in class ' . $cls,
                $mspan);
        }
        $this->checkVisibility($cls . '::' . $mname . '()', $vis, $inh, $mspan);
    }

    /** @param DeclTable[] $ifaceTables */
    private function checkOwnConst(string $cls, \Parser\Ast\ConstDecl $c, ?DeclTable $parentTable,
                                   array $ifaceTables, \Parser\Ast\Span $classSpan): void
    {
        $name = $c->name;
        $inh = $parentTable !== null ? ($parentTable->consts[$name] ?? null) : null;
        if ($inh === null) {
            foreach ($ifaceTables as $it) {
                $inh = $it->consts[$name] ?? null;
                if ($inh !== null) { break; }
            }
        }
        if ($inh === null) { return; }
        if ($inh->isFinal) {
            $this->declFail($cls . '::' . $name . ' cannot override final constant '
                . $inh->declClass . '::' . $inh->name, $classSpan);
        }
        $this->checkVisibility($cls . '::' . $name, $c->visibility === '' ? 'public' : $c->visibility,
            $inh, $classSpan);
    }

    private function checkOwnProps(\Parser\Ast\ClassDecl $d, string $cls, ?DeclTable $parentTable,
                                   \Parser\Ast\Span $classSpan): void
    {
        if ($parentTable === null) { return; }
        foreach ($d->properties as $p) {
            $inh = $parentTable->props[$p->name] ?? null;
            if ($inh === null || $inh->isStatic !== $p->isStatic) { continue; }
            $this->checkVisibility($cls . '::$' . $p->name,
                $p->visibility === '' ? 'public' : $p->visibility, $inh, $classSpan);
        }
        foreach ($d->methods as $m) {
            if (\strtolower($m->name) !== '__construct') { continue; }
            foreach ($m->params as $par) {
                if ($par->promoted === '') { continue; }
                $inh = $parentTable->props[$par->name] ?? null;
                if ($inh === null || $inh->isStatic) { continue; }
                $this->checkVisibility($cls . '::$' . $par->name, $par->promoted, $inh, $classSpan);
            }
        }
    }

    private function checkVisibility(string $what, string $vis, DeclMember $inh, \Parser\Ast\Span $span): void
    {
        $rank = $this->visRank($vis);
        $want = $this->visRank($inh->visibility);
        if ($rank <= $want) { return; }
        $this->declFail('Access level to ' . $what . ' must be ' . $inh->visibility
            . ' (as in class ' . $inh->declClass . ')' . ($want === 0 ? '' : ' or weaker'), $span);
    }

    private function visRank(string $vis): int
    {
        if ($vis === 'private') { return 2; }
        if ($vis === 'protected') { return 1; }
        return 0;
    }

    /**
     * Members `$name` exposes after inheritance, or null when it has no
     * declaration here. Memoised; a cyclic ancestry yields an incomplete table.
     */
    private function declTableOf(string $name): ?DeclTable
    {
        if (isset($this->declTables[$name])) { return $this->declTables[$name]; }
        $d0 = $this->classDecls[$name] ?? ($this->traitTable[$name] ?? null);
        if ($d0 === null) { return null; }
        $t = new DeclTable();
        if (isset($this->declTableBusy[$name])) {
            $t->complete = false;
            return $t;
        }
        $this->declTableBusy[$name] = true;
        $this->fillDeclTable($t, $this->classDeclOf($d0), $name);
        unset($this->declTableBusy[$name]);
        $this->declTables[$name] = $t;
        return $t;
    }

    private function fillDeclTable(DeclTable $t, \Parser\Ast\ClassDecl $d, string $cls): void
    {
        $kind = $d->kind;
        $isIface = $kind === 'interface';
        foreach ($d->methods as $m) {
            $t->methods[\strtolower($m->name)] = new DeclMember($m->name,
                $m->visibility === '' ? 'public' : $m->visibility, $m->isStatic,
                $m->isAbstract || $isIface, $m->isFinal, $cls);
        }
        foreach ($d->consts as $c) {
            $t->consts[$c->name] = new DeclMember($c->name,
                $c->visibility === '' ? 'public' : $c->visibility, false, false, $c->isFinal, $cls);
        }
        foreach ($d->properties as $p) {
            $t->props[$p->name] = new DeclMember($p->name,
                $p->visibility === '' ? 'public' : $p->visibility, $p->isStatic, false, false, $cls);
        }
        foreach ($d->methods as $m) {
            if (\strtolower($m->name) !== '__construct') { continue; }
            foreach ($m->params as $par) {
                if ($par->promoted === '') { continue; }
                $t->props[$par->name] = new DeclMember($par->name, $par->promoted, false, false, false, $cls);
            }
        }

        $parent = null;
        if ($kind === 'class' && $d->extends !== []) {
            $parent = $this->declTableOf(\ltrim($d->extends[0], '\\'));
            if ($parent === null || !$parent->complete) { $t->complete = false; }
        }

        foreach ($d->uses as $tn) {
            $tt = $this->declTableOf(\ltrim($tn, '\\'));
            if ($tt === null || !$tt->complete) { $t->complete = false; }
            if ($tt === null) { continue; }
            foreach ($tt->methods as $key => $mem) {
                $cur = $t->methods[$key] ?? null;
                if ($cur !== null && !($cur->fromTrait && $cur->isAbstract && !$mem->isAbstract)) { continue; }
                if ($mem->isAbstract && $cur === null && $parent !== null) {
                    $pm = $parent->methods[$key] ?? null;
                    if ($pm !== null && !$pm->isAbstract) { continue; }
                }
                $t->methods[$key] = new DeclMember($mem->name, $mem->visibility, $mem->isStatic,
                    $mem->isAbstract, $mem->isFinal, $cls, true);
            }
            foreach ($tt->props as $key => $mem) {
                if (!isset($t->props[$key])) {
                    $t->props[$key] = new DeclMember($mem->name, $mem->visibility, $mem->isStatic,
                        false, false, $cls, true);
                }
            }
        }
        foreach ($d->traitAdaptations as $a) {
            if ($a->kind !== 'as' || $a->alias === '') { continue; }
            $src = $t->methods[\strtolower($a->method)] ?? null;
            if ($src === null || isset($t->methods[\strtolower($a->alias)])) { continue; }
            $t->methods[\strtolower($a->alias)] = new DeclMember($a->alias,
                $a->visibility === '' ? $src->visibility : $a->visibility, $src->isStatic,
                $src->isAbstract, $src->isFinal, $cls, true);
        }

        if ($parent !== null) {
            foreach ($parent->methods as $key => $mem) {
                if ($mem->visibility === 'private' || isset($t->methods[$key])) { continue; }
                $t->methods[$key] = $mem;
            }
            foreach ($parent->props as $key => $mem) {
                if ($mem->visibility === 'private' || isset($t->props[$key])) { continue; }
                $t->props[$key] = $mem;
            }
            foreach ($parent->consts as $key => $mem) {
                if ($mem->visibility === 'private' || isset($t->consts[$key])) { continue; }
                $t->consts[$key] = $mem;
            }
        }

        $ifaces = $isIface ? $d->extends : $d->implements;
        foreach ($ifaces as $in) {
            $it = $this->declTableOf(\ltrim($in, '\\'));
            if ($it === null || !$it->complete) { $t->complete = false; }
            if ($it === null) { continue; }
            foreach ($it->methods as $key => $mem) {
                if (!isset($t->methods[$key])) { $t->methods[$key] = $mem; }
            }
            foreach ($it->consts as $key => $mem) {
                if (!isset($t->consts[$key])) { $t->consts[$key] = $mem; }
            }
        }
    }

    private function declFail(string $msg, \Parser\Ast\Span $span): void
    {
        if ($this->attrCollectMode) {
            $this->attrErrors[] = 'line ' . (string)$span->line . ': error: ' . $msg;
            return;
        }
        $file = $span->file;
        throw new \RuntimeException($msg . ($file === '' ? '' : ' in ' . $file) . ' on line ' . (string)$span->line);
    }
}
