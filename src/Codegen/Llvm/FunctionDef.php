<?php

namespace Codegen\Llvm;

/**
 * One LLVM-IR function parameter — type + name. Lifted out of the
 * earlier `[Type, string]` tuple form so the compiler can track its
 * class through chained access.
 */
final class FnParam
{
    public function __construct(
        public readonly Type $type,
        public readonly string $name,
    ) {}
}

/**
 * LLVM IR function definition (with body).
 *
 * Plain string concatenation only — no `{$obj->prop}` interpolation,
 * because the current AOT compiler does not reliably expand that form.
 */
final class FunctionDef
{
    /** @var Block[] */
    private array $blocks = [];

    /** @var FnParam[] */
    private array $params = [];

    public string $linkage     = '';
    public string $attrs       = '';
    public string $personality = '';  // e.g. "ptr @__gxx_personality_v0" — empty for no unwind

    /**
     * Per-function SSA-temp counter shared by every {@see Block} that
     * the function contains. LLVM IR requires SSA value names to be
     * unique inside one function, not just inside one block, so the
     * counter has to live here rather than on the individual blocks.
     */
    public int $tempCounter = 0;

    public function __construct(
        public readonly string $name,
        public readonly Type $returnType,
    ) {}

    public function param(Type $type, string $name): Value
    {
        $this->params[] = new FnParam($type, $name);
        return Value::reg($type, $name);
    }

    public function block(string $label): Block
    {
        // LLVM puts block labels and function params in the same value
        // namespace — a `%entry` parameter clashes with an `entry:`
        // label. Suffix the label if it collides.
        foreach ($this->params ?? [] as $p) {
            if ($p->name === $label) {
                $label = $label . '_bb';
                break;
            }
        }
        $b = new Block($label, $this);
        $this->blocks[] = $b;
        return $b;
    }

    public function emit(): string
    {
        $parts = [];
        foreach ($this->params as $p) {
            $parts[] = $p->type->text . ' %' . $p->name;
        }
        $paramList = implode(', ', $parts);
        $prefix = 'define';
        if ($this->linkage !== '') {
            $prefix = $this->linkage . ' ' . $prefix;
        }
        $suffix = '';
        if ($this->attrs !== '') {
            $suffix = ' ' . $this->attrs;
        }
        if ($this->personality !== '') {
            $suffix = $suffix . ' personality ' . $this->personality;
        }
        $out = $prefix . ' ' . $this->returnType->text . ' @' . $this->name
            . '(' . $paramList . ')' . $suffix . " {\n";
        foreach ($this->blocks as $b) {
            $out = $out . $b->emit();
        }
        $out = $out . "}\n";
        return $out;
    }
}
