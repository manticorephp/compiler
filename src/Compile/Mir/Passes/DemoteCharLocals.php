<?php

namespace Compile\Mir\Passes;

use Compile\Mir\ArrayAccess_;
use Compile\Mir\Call;
use Compile\Mir\Cmp;
use Compile\Mir\FunctionDef;
use Compile\Mir\IntConst;
use Compile\Mir\LoadLocal;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\StoreLocal;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * `$s[$i]` costs a MALLOC. Stop paying it when nobody looks at the string.
 *
 * A character read has to hand back a `string`, so `__mir_str_char_at` mints a
 * fresh 1-char headered buffer — 32-byte header, refcount, the lot — for every
 * byte read. Scanning a 2 MB string that way cost **100 MB of RSS** (measured;
 * `php` did the same scan in 29 MB). The compiler's own Lexer does exactly this
 * once per byte of every source file.
 *
 * But a scanner never *looks* at that string. It compares it to a one-character
 * literal, or passes it to `ord()`. In both cases the value observed is a BYTE,
 * and the string it arrived in is dead the moment it is born. So: prove the
 * character is never observed AS a string, and read the byte instead
 * (`__mir_str_byte_at` — a bounds-checked `load i8`, no allocation).
 *
 * Three shapes, all of them idiomatic PHP and all rewritten in place:
 *
 *     ord($s[$i])                       → __str_byte_at($s, $i)
 *     $s[$i] === 'x'                    → __str_byte_at($s, $i) === 120
 *     $c = $s[$i]; …; $c === 'x'        → $c is an int byte throughout
 *
 * The third needs the proof, and it is the one that matters: assigning the
 * character to a local first is how every real scanner is written (the compiler's
 * own Lexer included), and it is exactly the shape a node-local peephole misses.
 * A local is demoted only when EVERY definition of it is a character read and
 * EVERY use is a comparison against a one-character literal. One other use — a
 * concat, an argument, a return — and it stays a string, because then somebody
 * really does look at it.
 *
 * Out of range: `__mir_str_byte_at` yields 0, which is `ord("")` — the same value
 * `ord($s[$i])` gives today for an out-of-range read. A comparison against a
 * one-character literal is false either way, since no literal encodes byte 0.
 *
 * This buys MEMORY, not time: the arena bump-allocates, so the per-character
 * string was never slow — it was just enormous. (The Lexer is <1% of `bin/build`;
 * do not confuse this with a build-time optimisation.)
 */
final class DemoteCharLocals
{
    public const NAME = 'demote-char-locals';

    /** Locals proved to be bytes, in the function being rewritten.
     *  @var array<string, true> */
    private array $demoted = [];

    /** Every local's total LoadLocal count. @var array<string, int> */
    private array $useCount = [];

    /** Uses that are a comparison against a 1-char literal. @var array<string, int> */
    private array $byteUseCount = [];

    /** Locals whose every definition is a character read. @var array<string, bool> */
    private array $defIsCharRead = [];

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            $this->rewriteDirect($fn->body);
            $this->demoteLocals($fn);
        }
        return $module;
    }

    // ── The two shapes that need no proof ────────────────────────────────────

    /**
     * `ord($s[$i])` and `$s[$i] === 'x'` — the character never reaches a local, so
     * there is nothing to prove: it is born and observed as a byte in one breath.
     */
    private function rewriteDirect(Node $n): void
    {
        if ($n->kind === Node::KIND_CALL) {
            $this->rewriteOrd($n);
        } elseif ($n->kind === Node::KIND_CMP) {
            $this->rewriteCharCmp($n);
        }
        foreach (Walk::children($n) as $c) { $this->rewriteDirect($c); }
    }

    private function rewriteOrd(Call $n): void
    {
        if ($n->function !== 'ord' || \count($n->args) !== 1) { return; }
        $arg = $n->args[0];
        if (!$this->isCharRead($arg)) { return; }
        $n->function = '__str_byte_at';
        $n->args = $this->byteArgs($arg);
        $n->type = Type::int_();
    }

    /** `$s[$i] === 'x'` — read the byte, compare it to the literal's byte. */
    private function rewriteCharCmp(Cmp $n): void
    {
        if (!$this->isEqOp($n->op)) { return; }
        if ($this->isCharRead($n->left) && $this->charLiteralByte($n->right) >= 0) {
            $byte = $this->charLiteralByte($n->right);
            $n->left = $this->byteReadOf($n->left);
            $n->right = new IntConst($byte, Type::int_());
            return;
        }
        if ($this->isCharRead($n->right) && $this->charLiteralByte($n->left) >= 0) {
            $byte = $this->charLiteralByte($n->left);
            $n->right = $this->byteReadOf($n->right);
            $n->left = new IntConst($byte, Type::int_());
        }
    }

    // ── The shape that needs the proof ───────────────────────────────────────

    private function demoteLocals(FunctionDef $fn): void
    {
        $this->demoted = [];
        $this->useCount = [];
        $this->byteUseCount = [];
        $this->defIsCharRead = [];
        $this->scan($fn->body);

        // A parameter is a definition this pass cannot see, so it can never be
        // proved to hold only characters.
        foreach ($fn->params as $p) { $this->defIsCharRead[$p->name] = false; }

        foreach ($this->defIsCharRead as $name => $ok) {
            if (!$ok) { continue; }
            $uses = $this->useCount[$name] ?? 0;
            if ($uses === 0) { continue; }
            // EVERY use must be a byte comparison. One concat, argument or return
            // and somebody really does look at the string.
            if (($this->byteUseCount[$name] ?? 0) !== $uses) { continue; }
            $this->demoted[$name] = true;
        }
        if ($this->demoted !== []) { $this->rewriteDemoted($fn->body); }
    }

    /** Count each local's uses, byte-uses, and whether every def is a char read. */
    private function scan(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $this->scanStore($n);
        } elseif ($n->kind === Node::KIND_LOAD_LOCAL) {
            $this->scanLoad($n);
        } elseif ($n->kind === Node::KIND_CMP) {
            $this->scanCmp($n);
        }
        foreach (Walk::children($n) as $c) { $this->scan($c); }
    }

    private function scanStore(StoreLocal $n): void
    {
        $ok = $this->isCharRead($n->value);
        if (!$ok) {
            $this->defIsCharRead[$n->name] = false;
            return;
        }
        if (!isset($this->defIsCharRead[$n->name])) {
            $this->defIsCharRead[$n->name] = true;
        }
    }

    private function scanLoad(LoadLocal $n): void
    {
        $this->useCount[$n->name] = ($this->useCount[$n->name] ?? 0) + 1;
    }

    /** A `$c === 'x'` use — the one context in which the string is never observed. */
    private function scanCmp(Cmp $n): void
    {
        if (!$this->isEqOp($n->op)) { return; }
        $name = '';
        if ($n->left->kind === Node::KIND_LOAD_LOCAL
            && $this->charLiteralByte($n->right) >= 0) {
            $name = $this->localName($n->left);
        } elseif ($n->right->kind === Node::KIND_LOAD_LOCAL
            && $this->charLiteralByte($n->left) >= 0) {
            $name = $this->localName($n->right);
        }
        if ($name === '') { return; }
        $this->byteUseCount[$name] = ($this->byteUseCount[$name] ?? 0) + 1;
    }

    private function rewriteDemoted(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $this->rewriteDemotedStore($n);
        } elseif ($n->kind === Node::KIND_LOAD_LOCAL) {
            $this->rewriteDemotedLoad($n);
        } elseif ($n->kind === Node::KIND_CMP) {
            $this->rewriteDemotedCmp($n);
        }
        foreach (Walk::children($n) as $c) { $this->rewriteDemoted($c); }
    }

    private function rewriteDemotedStore(StoreLocal $n): void
    {
        if (!isset($this->demoted[$n->name])) { return; }
        $n->value = $this->byteReadOf($n->value);
        $n->type = Type::int_();
        $n->declaredType = null;
    }

    private function rewriteDemotedLoad(LoadLocal $n): void
    {
        if (!isset($this->demoted[$n->name])) { return; }
        $n->type = Type::int_();
    }

    private function rewriteDemotedCmp(Cmp $n): void
    {
        if (!$this->isEqOp($n->op)) { return; }
        if ($n->left->kind === Node::KIND_LOAD_LOCAL
            && isset($this->demoted[$this->localName($n->left)])
            && $this->charLiteralByte($n->right) >= 0) {
            $n->right = new IntConst($this->charLiteralByte($n->right), Type::int_());
            return;
        }
        if ($n->right->kind === Node::KIND_LOAD_LOCAL
            && isset($this->demoted[$this->localName($n->right)])
            && $this->charLiteralByte($n->left) >= 0) {
            $n->left = new IntConst($this->charLiteralByte($n->left), Type::int_());
        }
    }

    // ── Recognisers ─────────────────────────────────────────────────────────

    /** `$s[$i]` where `$s` really is a string (not an array). */
    private function isCharRead(Node $n): bool
    {
        if ($n->kind !== Node::KIND_ARRAY_ACCESS) { return false; }
        return $this->charReadSubject($n);
    }

    /** Read through a TYPED param: ArrayAccess_'s fields are its own. */
    private function charReadSubject(ArrayAccess_ $n): bool
    {
        return $n->array->type->kind === Type::KIND_STRING;
    }

    /** `__str_byte_at($s, $i)` for a `$s[$i]` node. */
    private function byteReadOf(Node $n): Node
    {
        return new Call('__str_byte_at', $this->byteArgs($n), Type::int_());
    }

    /** @return Node[] */
    private function byteArgs(Node $n): array
    {
        return $this->byteArgsOf($n);
    }

    /** @return Node[] */
    private function byteArgsOf(ArrayAccess_ $n): array
    {
        return [$n->array, $n->index];
    }

    private function localName(Node $n): string
    {
        return $this->loadName($n);
    }

    private function loadName(LoadLocal $n): string
    {
        return $n->name;
    }

    /**
     * The byte of a one-character string literal, or -1. A literal of any other
     * length (including `''`) is NOT a byte comparison — `$c === ''` asks whether
     * the read was out of range, which a demoted byte could not answer.
     */
    private function charLiteralByte(Node $n): int
    {
        if ($n->kind !== Node::KIND_STRING_CONST) { return -1; }
        return $this->constByte($n);
    }

    private function constByte(\Compile\Mir\StringConst $n): int
    {
        if (\strlen($n->value) !== 1) { return -1; }
        return \ord($n->value);
    }

    private function isEqOp(string $op): bool
    {
        return $op === '===' || $op === '!==' || $op === '==' || $op === '!=';
    }
}
