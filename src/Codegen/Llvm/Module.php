<?php

namespace Codegen\Llvm;

/**
 * Top-level LLVM IR module — declarations, global constants, and function
 * definitions in textual form.
 *
 * Plain string concatenation only — no `{$obj->prop}` interpolation,
 * because the current AOT compiler does not reliably expand that form.
 */
final class Module
{
    public string $targetTriple = '';
    public string $dataLayout   = '';

    /** @var string[] */
    private array $headers = [];

    /** @var string[] */
    private array $declarations = [];

    /** @var string[] */
    private array $globals = [];

    /** @var array<string, string> name → body for `%Name = type ...` lines */
    private array $namedTypes = [];

    /** @var FunctionDef[] */
    private array $functions = [];

    private static int $stringCounter = 0;

    public function __construct(public readonly string $name) {}

    public function header(string $line): void
    {
        $this->headers[] = $line;
    }

    /** @var array<string, true> Names of every external symbol we've already declared. */
    private array $declaredNames = [];

    public function hasDeclaration(string $name): bool
    {
        return isset($this->declaredNames[$name]);
    }

    /** @param Type[] $paramTypes */
    public function declare(
        string $name,
        Type $returnType,
        array $paramTypes,
        string $attrs = '',
        bool $variadic = false,
    ): void {
        if (isset($this->declaredNames[$name])) { return; }
        $this->declaredNames[$name] = true;
        $tps = [];
        foreach ($paramTypes as $t) {
            $tps[] = $t->text;
        }
        if ($variadic) {
            $tps[] = '...';
        }
        $params = implode(', ', $tps);
        $suffix = '';
        if ($attrs !== '') {
            $suffix = ' ' . $attrs;
        }
        $this->declarations[] = 'declare ' . $returnType->text . ' @' . $name
            . '(' . $params . ')' . $suffix;
    }

    public function globalCString(string $name, string $bytes): Value
    {
        $len = strlen($bytes) + 1;
        $arrType = '[' . (string)$len . ' x i8]';
        $escaped = self::escapeIrString($bytes) . '\\00';
        $this->globals[] = '@' . $name . ' = private unnamed_addr constant '
            . $arrType . ' c"' . $escaped . '", align 1';
        return Value::global(Type::ptr(), $name);
    }

    public function anonString(string $bytes): Value
    {
        // `.vstr.` (verify-string) prefix, NOT `.str.`: EmitLlvm's interned
        // string pool owns `@.str.N` with an independent counter, so sharing
        // the prefix collides (`redefinition of global @.str.1`) once both
        // emit. anonString is only used for MANTICORE_DEBUG_VERIFY messages.
        self::$stringCounter = self::$stringCounter + 1;
        return $this->globalCString('.vstr.' . (string)self::$stringCounter, $bytes);
    }

    public function rawGlobal(string $line): void
    {
        $this->globals[] = $line;
    }

    /**
     * Define a named struct type. Returns a `Type` that refers to it via
     * its `%Name` identifier so it can be used in calls and allocas.
     *
     *     $php_value = $m->namedStruct('PhpValue', '{ i64, i64 }');
     */
    public function namedStruct(string $name, string $body): Type
    {
        $this->namedTypes[$name] = $body;
        return Type::raw('%' . $name);
    }

    /**
     * Emit an integer global of fixed type. Example:
     *
     *     $m->globalInt('counter', Type::i64(), 0);
     */
    public function globalInt(string $name, Type $type, int $initial = 0, string $linkage = 'internal'): Value
    {
        $this->globals[] = '@' . $name . ' = ' . $linkage . ' global ' . $type->text . ' ' . (string)$initial;
        return Value::global(Type::ptr(), $name);
    }

    /**
     * Emit a NULL-initialized pointer global. Useful for FFI-resolved function
     * pointers populated at startup.
     */
    public function globalPtr(string $name, string $linkage = 'internal'): Value
    {
        $this->globals[] = '@' . $name . ' = ' . $linkage . ' global ptr null';
        return Value::global(Type::ptr(), $name);
    }

    public function func(string $name, Type $returnType): FunctionDef
    {
        // If we previously emitted a `declare` for this symbol (e.g.
        // an FFI binding registered the prototype before we knew we'd
        // also be defining the body), drop it so clang doesn't see
        // both `declare` and `define` for the same name.
        if (isset($this->declaredNames[$name])) {
            unset($this->declaredNames[$name]);
            $kept = [];
            $needle = ' @' . $name . '(';
            foreach ($this->declarations as $line) {
                if (str_contains($line, $needle)) { continue; }
                $kept[] = $line;
            }
            $this->declarations = $kept;
        }
        $f = new FunctionDef($name, $returnType);
        $this->functions[] = $f;
        return $f;
    }

    public function emit(): string
    {
        $out = "; ModuleID = '" . $this->name . "'\n";
        // Module name is hard-coded ('main') — no need to escape.
        $out = $out . 'source_filename = "' . $this->name . "\"\n";
        if ($this->targetTriple !== '') {
            $out = $out . 'target triple = "' . $this->targetTriple . "\"\n";
        }
        if ($this->dataLayout !== '') {
            $out = $out . 'target datalayout = "' . $this->dataLayout . "\"\n";
        }
        $out = $out . "\n";
        foreach ($this->headers as $h) {
            $out = $out . $h . "\n";
        }
        if ($this->headers !== []) {
            $out = $out . "\n";
        }
        foreach ($this->namedTypes as $name => $body) {
            $out = $out . '%' . $name . ' = type ' . $body . "\n";
        }
        if ($this->namedTypes !== []) {
            $out = $out . "\n";
        }
        foreach ($this->globals as $g) {
            $out = $out . $g . "\n";
        }
        if ($this->globals !== []) {
            $out = $out . "\n";
        }
        foreach ($this->declarations as $d) {
            $out = $out . $d . "\n";
        }
        if ($this->declarations !== []) {
            $out = $out . "\n";
        }
        foreach ($this->functions as $f) {
            $out = $out . $f->emit();
            $out = $out . "\n";
        }
        return $out;
    }

    /**
     * Render only the globals + function definitions (no ModuleID /
     * triple / declares header). For splicing a self-contained
     * runtime sub-module into a text-emitted module (MIR backend):
     * the host module owns the external declares, so this drops them
     * to avoid duplicate-`declare` redefinition errors.
     */
    public function emitFunctionsOnly(): string
    {
        $out = '';
        foreach ($this->globals as $g) {
            $out = $out . $g . "\n";
        }
        if ($this->globals !== []) {
            $out = $out . "\n";
        }
        foreach ($this->functions as $f) {
            $out = $out . $f->emit();
            $out = $out . "\n";
        }
        return $out;
    }

    public static function escapeIrString(string $bytes): string
    {
        $out = '';
        $n = strlen($bytes);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $b = ord($bytes[$i]);
            if ($b === 0x22 || $b === 0x5C || $b < 0x20 || $b > 0x7E) {
                $hex = strtoupper(dechex($b));
                if (strlen($hex) < 2) {
                    $hex = '0' . $hex;
                }
                $out = $out . '\\' . $hex;
            } else {
                $out = $out . chr($b);
            }
        }
        return $out;
    }
}
