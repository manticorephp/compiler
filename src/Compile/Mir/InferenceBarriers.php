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

    public static function scan(Module $module): self
    {
        $out = new self();
        foreach ($module->functions as $fn) {
            if ($fn->isPrelude) { continue; }
            $out->scanNode($fn->body);
        }
        return $out;
    }

    public function isEmpty(): bool { return \count($this->reasons) === 0; }
    public function nodeCount(): int { return $this->nodes; }
    public function reasonCount(): int { return \count($this->reasons); }
    public function reasons(): array { return \array_keys($this->reasons); }

    private function add(string $reason): void { $this->reasons[$reason] = true; }

    private function scanNode(Node $node): void
    {
        $this->nodes = $this->nodes + 1;
        switch ($node->kind) {
            case Node::KIND_INVOKE:
                $this->add('indirect-call');
                break;
            case Node::KIND_METHOD_CALL:
                $this->add('method-dispatch');
                break;
            case Node::KIND_STATIC_CALL:
                $this->add('static-dispatch');
                break;
            case Node::KIND_NEW_DYN_OBJ:
                $this->add('dynamic-class');
                break;
            case Node::KIND_DYN_PROP:
            case Node::KIND_STORE_DYN_PROP:
                $this->add('dynamic-property');
                break;
            case Node::KIND_STATIC_PROP:
            case Node::KIND_STORE_STATIC_PROP:
            case Node::KIND_STATIC_LOCAL_DECL:
                $this->add('shared-state');
                break;
            case Node::KIND_CLOSURE:
                $this->add('closure-capture');
                break;
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
