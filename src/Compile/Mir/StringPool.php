<?php
namespace Compile\Mir;
/**
 * Interns string literals for a single module emit.
 *
 * Each distinct string gets a stable dense id (`@.str.N`); the id is its
 * insertion order, so emitted globals and references remain deterministic.
 */
final class StringPool
{
    /** @var array<string, int> literal value -> dense id (lookup side only) */
    private array $pool = [];
    /** @var array<int, string> dense id -> literal value */
    private array $byId = [];
    /** @var array<int, bool> ids whose host string has a null data pointer */
    private array $emptyIds = [];

    public function intern(string $s): int
    {
        if (isset($this->pool[$s])) { return $this->pool[$s]; }
        $id = \count($this->pool);
        $this->pool[$s] = $id;
        $this->byId[$id] = $s;
        $this->emptyIds[$id] = \manticore_raw_str_bytes($s) === 0;
        return $id;
    }
    /** @return array<int, string> id -> value, in insertion order */
    public function all(): array { return $this->byId; }
    public function size(): int { return \count($this->byId); }
    public function hasId(int $id): bool { return \array_key_exists($id, $this->byId); }
    public function valueAt(int $id)
    {
        if (!\array_key_exists($id, $this->byId)) { return null; }
        return $this->byId[$id];
    }
    public function isEmpty(int $id): bool { return $this->emptyIds[$id] ?? false; }

    /** @var array<int, string> id -> its global's symbol */
    private array $symById = [];
    /** @var array<string, int> symbol -> the id that owns it */
    private array $idBySym = [];

    /**
     * The literal's global, named by its CONTENT: `@.str.<crc32><crc32'>`.
     *
     * The dense id is insertion order, so one literal added anywhere renumbered
     * every later `@.str.N` in the module — and with it every split part: a
     * one-line edit to the compiler changed all 16 parts, so the object cache
     * never hit. A content name moves only with the content. Two 32-bit CRCs
     * (the second over the value plus a separator) make a collision a practical
     * impossibility; one still gets the id appended rather than a wrong alias.
     */
    public function sym(int $id): string
    {
        if (isset($this->symById[$id])) { return $this->symById[$id]; }
        $v = $this->byId[$id] ?? '';
        $sym = '@.str.' . \dechex(\crc32($v)) . '_' . \dechex(\crc32($v . "\x1f"));
        if (isset($this->idBySym[$sym]) && $this->idBySym[$sym] !== $id) {
            $sym = $sym . '_' . (string)$id;
        }
        $this->idBySym[$sym] = $id;
        $this->symById[$id] = $sym;
        return $sym;
    }
}
