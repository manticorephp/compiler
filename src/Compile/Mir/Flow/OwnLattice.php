<?php

namespace Compile\Mir\Flow;

use Compile\Mir\Node;

/**
 * Per-point ownership of the rc locals of one function ({@see
 * \Compile\Mir\Passes\OwnershipFlow}): a state maps a managed name to
 *
 *   EMPTY (absent)  the slot holds null, or a word every drop no-ops on;
 *   Borrow(k)       an rc value of release class k this frame holds no count
 *                   of ({@see borrow}: encoded below BORROW_BASE);
 *   SCALAR          a raw non-rc word: never dropped, never retained;
 *   MIXDEAD         the representation differs per incoming path (below);
 *   CELLNIL         a CELL slot holding a word every cell drop no-ops on (a
 *                   boxed scalar or null): EMPTY for a cell class, a raw
 *                   word for any other;
 *   k > 0           Own(k) — k is a release class interned by the pass (a
 *                   flavor plus the slot type it releases by).
 *
 * Join: equal states keep; EMPTY is the identity; Own(k) ⊔ Borrow(k) = Own(k),
 * and the name is recorded as a CONFLICT the pass compensates with a retain on
 * the borrowed edge; Own meeting anything else non-empty (another class, a
 * borrow of another class, a raw word) is a MISMATCH: the slot's representation
 * differs per path, which the typing only allows for a name nobody reads past
 * the join — the result is MIXDEAD and the pass drops the owned value on each
 * edge that carries one (a read past it, or an edge with no statement position,
 * is reported through Verify); two borrows of different classes, or a borrow
 * and a raw word, are SCALAR.
 *
 * The per-node effects are precomputed by the pass (keyed by `spl_object_id`);
 * `transfer` applies them and records the incoming state each op needs.
 */
final class OwnLattice implements Lattice
{
    public const EMPTY = 0;
    private const BORROW_BASE = -16;
    public const SCALAR = -3;
    /** Past a representation MISMATCH: never dropped or retained, and an rc read
     *  of the name here is an error ({@see $deadRead}). */
    public const MIXDEAD = -4;
    /** A cell slot's non-rc word: absorbed by a cell class, a MISMATCH with
     *  any other Own — a raw class's drop does not no-op on a tagged word. */
    public const CELLNIL = -5;

    /** Store modes. */
    public const PLAIN = 0;
    public const SELF_MOVE = 1;
    public const SELF_APPEND = 2;
    /** `$x = $x` of one type: InferTypes re-labels the slot (a loop-head
     *  merge types it `cell` where a path left a raw word). A real cell is an
     *  alias copy — +1, the old dropped; anything else is left as it is. */
    public const SELF_COPY = 3;

    /** @var array<string, int> */
    public array $entryState = [];
    /** @var array<string, bool> names whose borrowed sources are forced to own */
    public array $force = [];

    /** @var array<int, string> */
    public array $storeName = [];
    /** @var array<int, int> */
    public array $storeState = [];
    /** @var array<int, int> */
    public array $storeKey = [];
    /** @var array<int, int> */
    public array $storeMode = [];
    /** @var array<int, bool> release classes a cell drop releases (`cell`, `mix*`) */
    public array $cellish = [];

    /** @var array<int, string[]> */
    public array $unsetNames = [];

    /** @var array<int, string> */
    public array $feValName = [];
    /** @var array<int, int> */
    public array $feValState = [];
    /** @var array<int, int> */
    public array $feValKey = [];
    /** @var array<int, string> */
    public array $feKeyName = [];
    /** @var array<int, int> */
    public array $feKeyState = [];
    /** @var array<int, int> */
    public array $feKeyKey = [];

    /** @var array<int, string> rc-typed LoadLocal id → name (Unset_ targets and
     *  the pass's own op targets excluded) */
    public array $loadName = [];
    /** @var array<int, string> LoadLocal id → name, for a local a container
     *  store takes without a count of its own: Own(k) becomes Borrow(k) there */
    public array $moveName = [];
    /** @var array<int, string> LoadLocal id → name, for a container store the
     *  local stays live past: the container takes its own +1 of the Own value */
    public array $shareName = [];
    /** @var array<int, int> share LoadLocal id → the class the local owns there */
    public array $shareKey = [];
    /** @var array<string, int> name → line of an rc read past a mismatch */
    public array $deadRead = [];
    /** @var array<int, string> LoadLocal id → name, for a local a by-ref
     *  parameter receives: it owns its value from the call on */
    public array $refArgName = [];
    /** @var array<int, Node> by-ref argument id → the statement it sits in */
    public array $refArgAt = [];
    /** @var array<int, int> by-ref argument id → the class an EMPTY local owns
     *  after the call (0: none) */
    public array $refArgKey = [];
    /** @var array<int, int> by-ref argument id → the local's state there: a
     *  Borrow is owed a retain before the statement */
    public array $refArgIn = [];


    /** @var array<int, string> compensation op id → name */
    public array $opName = [];
    /** @var array<int, int> */
    public array $opKey = [];
    /** @var array<int, bool> the op is a `drop` (else an `own_retain`) */
    public array $opDrop = [];

    // ── records ────────────────────────────────────────────────────

    /** @var array<int, int> store id → the name's state right before the store */
    public array $storeIn = [];
    /** @var array<int, int> foreach id → the value var's state at the head */
    public array $feValIn = [];
    /** @var array<int, int> */
    public array $feKeyIn = [];
    /** @var array<int, array<string, int>> unset id → state before */
    public array $unsetIn = [];

    /** @var array<string, bool> */
    public array $conflicts = [];
    /** @var array<string, string> name → the two classes that met */
    public array $mismatch = [];
    /** @var array<string, bool> a compensation retain met an owned value, or a
     *  compensation drop met one that is not */
    public array $doubleRetain = [];

    /** @var string[] */
    public array $fixKind = [];
    /** @var string[] `own_retain` or `drop` */
    public array $fixOp = [];
    /** @var Node[] */
    public array $fixAt = [];
    /** @var Node[] the predecessor node, or `$at` when it has none */
    public array $fixPred = [];
    /** @var bool[] */
    public array $fixNoPred = [];
    /** @var string[] */
    public array $fixName = [];
    /** @var int[] */
    public array $fixKey = [];

    /** @var Node[] */
    public array $retAt = [];
    /** @var array<int, array<string, int>> */
    public array $retOut = [];
    /** @var Node[] every `throw` reached */
    public array $thrAt = [];
    /** @var array<int, array<string, int>> the state it throws in */
    public array $thrOut = [];

    /**
     * The ONE map from an {@see \Compile\Mir\Ownership} code to a state, for a
     * slot of release class `$key`: > 0 Own($key) — the SLOT's class, never the
     * code's —, 0 Borrow($key), -1 EMPTY.
     */
    public static function stateOf(int $code, int $key): int
    {
        if ($code > 0) { return $key; }
        if ($code === 0) { return self::borrow($key); }
        return self::EMPTY;
    }

    public static function borrow(int $key): int
    {
        return self::BORROW_BASE - $key;
    }

    public static function isBorrow(int $s): bool
    {
        return $s < self::BORROW_BASE;
    }

    public static function borrowKey(int $s): int
    {
        return self::BORROW_BASE - $s;
    }

    public static function show(int $s): string
    {
        if ($s === self::EMPTY) { return 'Empty'; }
        if (self::isBorrow($s)) { return 'Borrow:' . (string)self::borrowKey($s); }
        if ($s === self::SCALAR) { return 'Scalar'; }
        if ($s === self::MIXDEAD) { return 'MixDead'; }
        if ($s === self::CELLNIL) { return 'CellNil'; }
        return 'Own:' . (string)$s;
    }

    /** @return array<string, int> */
    public function entry(): array
    {
        return $this->entryState;
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     * @return array<string, int>
     */
    public function join(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $n => $x) {
            $y = $b[$n] ?? self::EMPTY;
            $v = $this->meet($n, $x, $y);
            if ($v !== self::EMPTY) { $out[$n] = $v; }
        }
        foreach ($b as $n => $y) {
            if (isset($a[$n])) { continue; }
            if ($y !== self::EMPTY) { $out[$n] = $y; }
        }
        return $out;
    }

    private function meet(string $n, int $x, int $y): int
    {
        if ($x === $y) { return $x; }
        if ($x === self::EMPTY) { return $y; }
        if ($y === self::EMPTY) { return $x; }
        if ($x === self::CELLNIL || $y === self::CELLNIL) {
            $o = $x === self::CELLNIL ? $y : $x;
            if ($o > 0) {
                if (isset($this->cellish[$o])) { return $o; }
                $this->mismatch[$n] = self::show($o) . ' vs ' . self::show(self::CELLNIL);
                return self::MIXDEAD;
            }
            if (self::isBorrow($o) && isset($this->cellish[self::borrowKey($o)])) { return $o; }
            return $o === self::MIXDEAD ? self::MIXDEAD : self::SCALAR;
        }
        if ($x > 0 || $y > 0) {
            $own = $x > 0 ? $x : $y;
            $other = $x > 0 ? $y : $x;
            if (self::isBorrow($other) && self::borrowKey($other) === $own) {
                $this->conflicts[$n] = true;
                return $own;
            }
            $this->mismatch[$n] = self::show($own) . ' vs ' . self::show($other);
            return self::MIXDEAD;
        }
        if ($x === self::MIXDEAD || $y === self::MIXDEAD) { return self::MIXDEAD; }
        return self::SCALAR;
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     */
    public function equal(array $a, array $b): bool
    {
        if (\count($a) !== \count($b)) { return false; }
        foreach ($a as $n => $x) {
            if (!isset($b[$n]) || $b[$n] !== $x) { return false; }
        }
        return true;
    }

    /**
     * @param array<string, int> $in
     * @return array<string, int>
     */
    public function transfer(Node $stmt, array $in): array
    {
        $id = \spl_object_id($stmt);
        if (isset($this->storeName[$id])) {
            $n = $this->storeName[$id];
            $x = $in[$n] ?? self::EMPTY;
            $this->storeIn[$id] = $x;
            $mode = $this->storeMode[$id];
            $v = $x;
            if ($mode === self::SELF_MOVE) {
                // The box-back moves the one reference — owned or borrowed —
                // into the cell, whose class it now is; a raw scalar boxes into
                // a cell every drop no-ops on.
                if ($x > 0) { $v = $this->storeKey[$id]; }
                if (self::isBorrow($x)) { $v = self::borrow($this->storeKey[$id]); }
                if ($x === self::SCALAR || $x === self::CELLNIL) { $v = self::CELLNIL; }
            } elseif ($mode === self::SELF_COPY) {
                if ($x > 0 && isset($this->cellish[$x])) { $v = $this->storeKey[$id]; }
                // A cell self-copy stores `__mir_cell_own_alias`'s answer, a
                // value the slot owns: a borrowed cell leaves it owned.
                if (self::isBorrow($x) && isset($this->cellish[self::borrowKey($x)])) { $v = $this->storeKey[$id]; }
            } elseif ($mode === self::SELF_APPEND) {
                $v = $this->storeKey[$id];
            } else {
                $v = $this->storeState[$id];
                if (self::isBorrow($v) && isset($this->force[$n])) { $v = self::borrowKey($v); }
            }
            return $this->with($in, $n, $v);
        }
        if (isset($this->unsetNames[$id])) {
            $this->unsetIn[$id] = $in;
            $out = $in;
            foreach ($this->unsetNames[$id] as $n) { unset($out[$n]); }
            return $out;
        }
        if (isset($this->feValName[$id]) || isset($this->feKeyName[$id])) {
            $out = $in;
            if (isset($this->feValName[$id])) {
                $n = $this->feValName[$id];
                $this->feValIn[$id] = $in[$n] ?? self::EMPTY;
                $v = $this->feValState[$id];
                if (self::isBorrow($v) && isset($this->force[$n])) { $v = self::borrowKey($v); }
                $out = $this->with($out, $n, $v);
            }
            if (isset($this->feKeyName[$id])) {
                $n = $this->feKeyName[$id];
                $this->feKeyIn[$id] = $in[$n] ?? self::EMPTY;
                $v = $this->feKeyState[$id];
                if (self::isBorrow($v) && isset($this->force[$n])) { $v = self::borrowKey($v); }
                $out = $this->with($out, $n, $v);
            }
            return $out;
        }
        if (isset($this->loadName[$id])) {
            $n = $this->loadName[$id];
            $x = $in[$n] ?? self::EMPTY;
            if ($x === self::MIXDEAD) { $this->deadRead[$n] = $stmt->line; }
            if (isset($this->refArgName[$id])) {
                $this->refArgIn[$id] = $x;
                if (self::isBorrow($x)) { return $this->with($in, $n, self::borrowKey($x)); }
                $rk = $this->refArgKey[$id] ?? 0;
                if (($x === self::EMPTY || $x === self::CELLNIL) && $rk > 0) { return $this->with($in, $n, $rk); }
                return $in;
            }
            if ($x > 0 && isset($this->moveName[$id])) { return $this->with($in, $n, self::borrow($x)); }
            if ($x > 0 && isset($this->shareName[$id])) { $this->shareKey[$id] = $x; }
            return $in;
        }
        if (isset($this->opName[$id])) {
            $n = $this->opName[$id];
            $x = $in[$n] ?? self::EMPTY;
            if ($this->opDrop[$id]) {
                if ($x <= 0) { $this->doubleRetain[$n] = true; }
                return $this->with($in, $n, self::EMPTY);
            }
            if (self::isBorrow($x)) {
                if (self::borrowKey($x) !== $this->opKey[$id]) { $this->doubleRetain[$n] = true; }
                return $this->with($in, $n, $this->opKey[$id]);
            }
            if ($x > 0) { $this->doubleRetain[$n] = true; }
            return $in;
        }
        return $in;
    }


    /**
     * @param array<string, int> $out
     * @param array<string, int> $joined
     */
    public function onEdge(string $kind, Node $at, ?Node $pred, array $out, array $joined): void
    {
        if ($kind === 'return') {
            $this->retAt[] = $at;
            $this->retOut[] = $out;
            return;
        }
        if ($kind === 'throw') {
            $this->thrAt[] = $at;
            $this->thrOut[] = $out;
            return;
        }
        foreach ($out as $n => $o) {
            if ($o <= 0) { continue; }
            if (($joined[$n] ?? self::EMPTY) !== self::MIXDEAD) { continue; }
            $this->fixKind[] = $kind;
            $this->fixOp[] = 'drop';
            $this->fixAt[] = $at;
            $this->fixPred[] = $pred === null ? $at : $pred;
            $this->fixNoPred[] = $pred === null;
            $this->fixName[] = $n;
            $this->fixKey[] = $o;
        }
        foreach ($joined as $n => $j) {
            if ($j <= 0) { continue; }
            $o = $out[$n] ?? self::EMPTY;
            if (!self::isBorrow($o) || self::borrowKey($o) !== $j) { continue; }
            $this->fixKind[] = $kind;
            $this->fixOp[] = 'own_retain';
            $this->fixAt[] = $at;
            $this->fixPred[] = $pred === null ? $at : $pred;
            $this->fixNoPred[] = $pred === null;
            $this->fixName[] = $n;
            $this->fixKey[] = $j;
        }
    }

    /**
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function with(array $s, string $n, int $v): array
    {
        if ($v === self::EMPTY) {
            unset($s[$n]);
        } else {
            $s[$n] = $v;
        }
        return $s;
    }
}
