<?php
namespace Compile\Mir;

/**
 * Conservative detector for facts that make function-subset inference unsafe.
 * It is analysis-only and never mutates MIR.
 */
final class InferenceBarriers
{
    private array $reasons = [];
    private int $nodes = 0;
    /** Functions carrying at least one barrier construct. */
    private array $escapers = [];
    /** reason => how many functions carry it. */
    private array $reasonFns = [];
    private string $fn = '';

    public static function scan(Module $module): self
    {
        $out = new self();
        foreach ($module->functions as $fn) {
            // Prelude bodies are scanned too: an unscanned function is neither an
            // escaper nor edge-connected, i.e. invisible to both halves of the
            // scope decision, which is the one state that is unsound.
            $out->fn = $fn->name;
            $out->scanNode($fn->body);
        }
        return $out;
    }

    public function isEmpty(): bool { return \count($this->reasons) === 0; }
    public function nodeCount(): int { return $this->nodes; }
    public function reasonCount(): int { return \count($this->reasons); }
    public function reasons(): array { return \array_keys($this->reasons); }

    public function escaperCount(): int { return \count($this->escapers); }
    /** Functions whose dependencies this analysis cannot see through — the
     *  set that must be re-inferred on EVERY round rather than vetoing the
     *  whole targeted mode. @return array<string, bool> */
    public function escapers(): array { return $this->escapers; }
    /** @return array<string, int> */
    public function reasonFnCounts(): array { return $this->reasonFns; }

    private function add(string $reason): void
    {
        $this->reasons[$reason] = true;
        if ($this->fn === '') { return; }
        if (!isset($this->escapers[$this->fn])) { $this->escapers[$this->fn] = true; }
        $key = $reason . '|' . $this->fn;
        if (isset($this->reasonFns[$key])) { return; }
        $this->reasonFns[$key] = 1;
    }

    private function scanNode(Node $node): void
    {
        $this->nodes = $this->nodes + 1;
        // Dispatch (dynamic or static), `new $cls`, dynamic and static
        // properties, closures and indirect calls are EDGES in
        // {@see DependencyIndex}; only what it cannot see stays a barrier.
        switch ($node->kind) {
            case Node::KIND_REF_BIND:
            case Node::KIND_REF_ALIAS:
            case Node::KIND_REF_ADDR:
            case Node::KIND_REF_CELL:
                $this->add('reference-aliasing');
                break;
        }
        foreach (Walk::children($node) as $child) { $this->scanNode($child); }
    }
}
