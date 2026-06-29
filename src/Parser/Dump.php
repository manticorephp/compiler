<?php

namespace Parser;

use Parser\Ast\AttributeNode;
use Parser\Ast\Block;
use Parser\Ast\ClassDecl;
use Parser\Ast\ConstDecl;
use Parser\Ast\Expr;
use Parser\Ast\FunctionDecl;
use Parser\Ast\MethodDecl;
use Parser\Ast\Param;
use Parser\Ast\Program;
use Parser\Ast\PropertyDecl;
use Parser\Ast\Span;
use Parser\Ast\Stmt;
use Parser\Ast\UseItem;

/**
 * AST dumper. Produces a stable, human-readable textual form that the
 * bootstrap test suite can pin in expected-output files.
 *
 * The format is line-oriented and indented by two spaces per nesting level.
 * It is not LLVM IR-style; it is intended for parser regression tests, not
 * for downstream consumption.
 */
final class Dump
{
    private int $indent = 0;
    private string $out = '';

    public static function program(Program $program): string
    {
        $d = new self();
        $d->program_($program);
        return $d->out;
    }

    private function program_(Program $p): void
    {
        $this->line('Program');
        $this->indent = $this->indent + 1;
        foreach ($p->statements as $s) {
            $this->stmt($s);
        }
        $this->indent = $this->indent - 1;
    }

    private function stmt(Stmt $s): void
    {
        $this->line('Stmt:' . $s->kind . ' ' . $this->spanStr($s->span));
        $this->indent = $this->indent + 1;
        $this->payload($s);
        $this->indent = $this->indent - 1;
    }

    private function expr(Expr $e): void
    {
        $this->line('Expr:' . $e->kind . ' ' . $this->spanStr($e->span));
        $this->indent = $this->indent + 1;
        $this->payload($e);
        $this->indent = $this->indent - 1;
    }

    /**
     * Generic dump for any AST node: walk every public property
     * except the shared `kind` / `span` already printed in the
     * header, formatting children via {@see field()}.
     */
    private function payload(object $node): void
    {
        foreach (get_object_vars($node) as $key => $value) {
            if ($key === 'kind' || $key === 'span') { continue; }
            $this->field((string)$key, $value);
        }
    }

    private function shortClass(object $o): string
    {
        $n = get_class($o);
        $i = strrpos($n, '\\');
        return $i === false ? $n : substr($n, $i + 1);
    }

    private function field(string $name, mixed $value): void
    {
        if ($value instanceof Expr) {
            $this->line($name . ':');
            $this->indent = $this->indent + 1;
            $this->expr($value);
            $this->indent = $this->indent - 1;
            return;
        }
        if ($value instanceof Stmt) {
            $this->line($name . ':');
            $this->indent = $this->indent + 1;
            $this->stmt($value);
            $this->indent = $this->indent - 1;
            return;
        }
        if ($value instanceof Block) {
            $this->line($name . ': Block');
            $this->indent = $this->indent + 1;
            foreach ($value->statements as $s) {
                $this->stmt($s);
            }
            $this->indent = $this->indent - 1;
            return;
        }
        if ($value instanceof FunctionDecl) {
            $this->line($name . ': FunctionDecl ' . $value->name);
            $this->indent = $this->indent + 1;
            $this->line('return: ' . ($value->returnType ?? '(none)'));
            $this->line('params: ' . (string)count($value->params));
            foreach ($value->params as $p) {
                $this->param($p);
            }
            $this->field('body', $value->body);
            $this->indent = $this->indent - 1;
            return;
        }
        if ($value instanceof ClassDecl) {
            $modifiers = [];
            if ($value->isFinal)    { $modifiers[] = 'final'; }
            if ($value->isAbstract) { $modifiers[] = 'abstract'; }
            if ($value->isReadonly) { $modifiers[] = 'readonly'; }
            $modText = $modifiers === [] ? '' : (' [' . implode(' ', $modifiers) . ']');
            $this->line($name . ': ' . $value->kind . ' ' . $value->name . $modText);
            $this->indent = $this->indent + 1;
            if ($value->enumBackingType !== null) {
                $this->line('backing: ' . $value->enumBackingType);
            }
            if ($value->extends !== []) {
                $this->line('extends: ' . implode(', ', $value->extends));
            }
            if ($value->implements !== []) {
                $this->line('implements: ' . implode(', ', $value->implements));
            }
            foreach ($value->attributes as $a) { $this->attribute($a); }
            foreach ($value->cases as $c) {
                $this->line('Case ' . $c[0]);
                if ($c[1] !== null) {
                    $this->indent = $this->indent + 1;
                    $this->field('value', $c[1]);
                    $this->indent = $this->indent - 1;
                }
            }
            foreach ($value->consts as $cd) { $this->constDecl($cd); }
            foreach ($value->properties as $pd) { $this->propertyDecl($pd); }
            foreach ($value->methods as $md) { $this->methodDecl($md); }
            $this->indent = $this->indent - 1;
            return;
        }
        if ($value instanceof MethodDecl) {
            $this->methodDecl($value);
            return;
        }
        if ($value instanceof PropertyDecl) {
            $this->propertyDecl($value);
            return;
        }
        if ($value instanceof ConstDecl) {
            $this->constDecl($value);
            return;
        }
        if ($value instanceof UseItem) {
            $alias = $value->alias === null ? '' : ' as ' . $value->alias;
            $this->line('UseItem ' . $value->kind . ' ' . $value->fqn . $alias);
            return;
        }
        if ($value instanceof AttributeNode) {
            $this->attribute($value);
            return;
        }
        if ($value instanceof Param) {
            $this->param($value);
            return;
        }
        if ($value instanceof Span) {
            $this->line($name . ': ' . $this->spanStr($value));
            return;
        }
        if (is_array($value)) {
            $this->line($name . ': [' . (string)count($value) . ']');
            $this->indent = $this->indent + 1;
            foreach ($value as $i => $item) {
                if ($item instanceof Expr) {
                    $this->expr($item);
                } elseif ($item instanceof Stmt) {
                    $this->stmt($item);
                } elseif ($item instanceof Param) {
                    $this->param($item);
                } elseif ($item instanceof UseItem) {
                    $alias = $item->alias === null ? '' : ' as ' . $item->alias;
                    $this->line('UseItem ' . $item->kind . ' ' . $item->fqn . $alias);
                } elseif ($item instanceof AttributeNode) {
                    $this->attribute($item);
                } elseif (is_object($item)) {
                    // AST helper records (ElseIfArm, SwitchArm,
                    // CatchClause, MatchArm, ArrayElement, ClosureUse,
                    // StaticLocalDecl) — dump as anonymous "Item N"
                    // with their public properties.
                    $this->line('[' . (string)$i . '] ' . $this->shortClass($item));
                    $this->indent = $this->indent + 1;
                    foreach (get_object_vars($item) as $k => $v) {
                        $this->field($k, $v);
                    }
                    $this->indent = $this->indent - 1;
                } elseif (is_array($item)) {
                    $this->line('[' . (string)$i . '] (array of ' . count($item) . ')');
                } else {
                    $this->line('[' . (string)$i . '] ' . $this->scalar($item));
                }
            }
            $this->indent = $this->indent - 1;
            return;
        }
        if (is_object($value)) {
            $this->line($name . ': ' . $this->shortClass($value));
            $this->indent = $this->indent + 1;
            foreach (get_object_vars($value) as $k => $v) {
                $this->field($k, $v);
            }
            $this->indent = $this->indent - 1;
            return;
        }
        if ($value === null) {
            $this->line($name . ': null');
            return;
        }
        $this->line($name . ': ' . $this->scalar($value));
    }

    private function param(Param $p): void
    {
        $type = $p->typeHint ?? '(untyped)';
        $flags = [];
        if ($p->byRef)    { $flags[] = '&'; }
        if ($p->variadic) { $flags[] = '...'; }
        if ($p->promoted !== '') {
            $flags[] = $p->promoted;
            if ($p->promotedReadonly) { $flags[] = 'readonly'; }
        }
        $flagText = $flags === [] ? '' : (' [' . implode(' ', $flags) . ']');
        $this->line('Param $' . $p->name . ' : ' . $type . $flagText . ' ' . $this->spanStr($p->span));
        $this->indent = $this->indent + 1;
        foreach ($p->attributes as $a) { $this->attribute($a); }
        if ($p->default !== null) {
            $this->field('default', $p->default);
        }
        $this->indent = $this->indent - 1;
    }

    private function propertyDecl(PropertyDecl $p): void
    {
        $flags = [$p->visibility];
        if ($p->isStatic)   { $flags[] = 'static'; }
        if ($p->isReadonly) { $flags[] = 'readonly'; }
        $type = $p->typeHint ?? '(untyped)';
        $this->line('Property $' . $p->name . ' : ' . $type
            . ' [' . implode(' ', $flags) . '] ' . $this->spanStr($p->span));
        $this->indent = $this->indent + 1;
        foreach ($p->attributes as $a) { $this->attribute($a); }
        if ($p->default !== null) {
            $this->field('default', $p->default);
        }
        $this->indent = $this->indent - 1;
    }

    private function methodDecl(MethodDecl $m): void
    {
        $flags = [$m->visibility];
        if ($m->isStatic)   { $flags[] = 'static'; }
        if ($m->isFinal)    { $flags[] = 'final'; }
        if ($m->isAbstract) { $flags[] = 'abstract'; }
        $ret = $m->returnType ?? '(none)';
        $this->line('Method ' . $m->name . ' [' . implode(' ', $flags) . '] -> ' . $ret
            . ' ' . $this->spanStr($m->span));
        $this->indent = $this->indent + 1;
        foreach ($m->attributes as $a) { $this->attribute($a); }
        $this->line('params: ' . (string)count($m->params));
        foreach ($m->params as $p) { $this->param($p); }
        if ($m->body !== null) {
            $this->field('body', $m->body);
        }
        $this->indent = $this->indent - 1;
    }

    private function constDecl(ConstDecl $c): void
    {
        $vis = $c->visibility === '' ? '' : (' [' . $c->visibility . ']');
        $type = $c->typeHint === null ? '' : (' : ' . $c->typeHint);
        $this->line('Const ' . $c->name . $type . $vis . ' ' . $this->spanStr($c->span));
        $this->indent = $this->indent + 1;
        foreach ($c->attributes as $a) { $this->attribute($a); }
        $this->field('value', $c->value);
        $this->indent = $this->indent - 1;
    }

    private function attribute(AttributeNode $a): void
    {
        $this->line('#[' . $a->name . '] ' . $this->spanStr($a->span)
            . ' args: ' . (string)count($a->args));
        if ($a->args !== []) {
            $this->indent = $this->indent + 1;
            foreach ($a->args as $arg) {
                $this->expr($arg);
            }
            $this->indent = $this->indent - 1;
        }
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_string($value)) {
            return '"' . addslashes($value) . '"';
        }
        return get_debug_type($value);
    }

    private function spanStr(Span $s): string
    {
        return '@' . (string)$s->line . ':' . (string)$s->column;
    }

    private function line(string $text): void
    {
        $this->out .= str_repeat('  ', $this->indent) . $text . "\n";
    }
}
