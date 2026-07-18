<?php

namespace Analyze\Rules;

use Analyze\Decls;
use Analyze\Diagnostic;
use Analyze\DocType;
use Analyze\ParsedFile;
use Compile\TypeHint\GenericType;
use Parser\Ast\FunctionDecl;
use Parser\Ast\MethodDecl;
use Parser\Ast\Param;
use Parser\Ast\PropertyDecl;

/**
 * Warns where a parameter, return, or property is typed the BARE `array`
 * (or `?array`) with no element type given — the same gap phpstan flags at its
 * higher levels ("no value type specified in iterable type array"). A value
 * type may be supplied natively (`array<K,V>`, `T[]`) OR by a docblock
 * (`@param T[] $x`, `@return T[]`, `@var T[]`); only when NEITHER does is it a
 * finding.
 *
 * Warning, not error — bare `array` is valid PHP; this is a strictness nudge
 * toward fully-typed signatures, which is also what lets the type engine reason
 * about element types downstream.
 */
final class MissingArrayType
{
    /** @var Diagnostic[] */
    public array $diags = [];

    /** @return Diagnostic[] */
    public function run(ParsedFile $pf): array
    {
        $decls = new Decls();
        $decls->collect($pf->program->statements);

        foreach ($decls->functions as $fn) {
            $this->checkFunction($pf, $fn->name . '()', $fn);
        }
        $i = 0;
        foreach ($decls->methods as $m) {
            $label = $decls->methodClasses[$i] . '::' . $m->name . '()';
            $i = $i + 1;
            $this->checkMethod($pf, $label, $m);
        }
        $j = 0;
        foreach ($decls->properties as $p) {
            $this->checkProperty($pf, $decls->propertyClasses[$j], $p);
            $j = $j + 1;
        }
        return $this->diags;
    }

    private function checkFunction(ParsedFile $pf, string $label, FunctionDecl $fn): void
    {
        foreach ($fn->params as $p) {
            $this->checkParam($pf, $label, $fn->docComment, $p);
        }
        if ($this->isBareArray($fn->returnType)
            && !$this->docHasElement(DocType::tag($fn->docComment, '@return', ''))) {
            $this->warn($pf, $fn->span->line, $fn->span->column,
                $label . ': return type is bare `array` — annotate its element type (`@return T[]`)');
        }
    }

    private function checkMethod(ParsedFile $pf, string $label, MethodDecl $m): void
    {
        foreach ($m->params as $p) {
            $this->checkParam($pf, $label, $m->docComment, $p);
        }
        if ($this->isBareArray($m->returnType)
            && !$this->docHasElement(DocType::tag($m->docComment, '@return', ''))) {
            $this->warn($pf, $m->span->line, $m->span->column,
                $label . ': return type is bare `array` — annotate its element type (`@return T[]`)');
        }
    }

    private function checkParam(ParsedFile $pf, string $label, ?string $doc, Param $p): void
    {
        if (!$this->isBareArray($p->typeHint)) { return; }
        if ($this->docHasElement(DocType::tag($doc, '@param', $p->name))) { return; }
        $this->warn($pf, $p->span->line, $p->span->column,
            $label . ': parameter $' . $p->name . ' is typed bare `array` — annotate its element type (`@param T[] $' . $p->name . '`)');
    }

    private function checkProperty(ParsedFile $pf, string $cls, PropertyDecl $p): void
    {
        if (!$this->isBareArray($p->typeHint)) { return; }
        // A property `@var` usually omits the variable name; try the named form
        // first, then the bare `@var T[]` form.
        $docType = DocType::tag($p->docComment, '@var', $p->name);
        if ($docType === null) { $docType = DocType::tag($p->docComment, '@var', ''); }
        if ($this->docHasElement($docType)) { return; }
        $this->warn($pf, $p->span->line, $p->span->column,
            'property ' . $cls . '::$' . $p->name . ' is typed bare `array` — annotate its element type (`@var T[]`)');
    }

    /** A hint that is exactly `array` or `?array` (no `<…>` / `[]` element). */
    private function isBareArray(?string $hint): bool
    {
        if ($hint === null) { return false; }
        $h = \strtolower($hint);
        if (\strlen($h) > 0 && \substr($h, 0, 1) === '?') { $h = \substr($h, 1); }
        return $h === 'array';
    }

    /** True when a docblock type string pins an array element/value type. */
    private function docHasElement(?string $docType): bool
    {
        if ($docType === null) { return false; }
        $g = GenericType::parse($docType);
        if ($g === null) { return false; }
        return $g->isArraySugar || \count($g->params) > 0;
    }

    private function warn(ParsedFile $pf, int $line, int $col, string $msg): void
    {
        $this->diags[] = Diagnostic::warning($pf->path, $line, $col, 'array.no-value-type', $msg);
    }
}
