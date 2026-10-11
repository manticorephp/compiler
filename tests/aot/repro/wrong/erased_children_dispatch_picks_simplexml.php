<?php
// ->children() on a receiver that came out of a dynamic ->{$name}() call dispatches to SimpleXMLElement::children() once the program mentions SimpleXMLElement
// issue: #182
interface ParentDef {}

abstract class NodeDef implements ParentDef
{
    protected ?ParentDef $parent = null;
    protected string $name;
    protected array $infoText = [];

    public function __construct(string $name, ?ParentDef $parent = null)
    {
        $this->name = $name;
        $this->parent = $parent;
    }
    public function info(string $info): static { $this->infoText[] = $info; return $this; }
    public function end(): ?ParentDef { return $this->parent; }
    public function name(): string { return $this->name; }
}

class ScalarDef extends NodeDef {}

class ArrayDef extends NodeDef
{
    protected ?NodeBuilder $builder = null;
    protected bool $enabled = false;

    public function children(): NodeBuilder
    {
        return $this->builder ??= new NodeBuilder($this);
    }
    public function canBeEnabled(): static { $this->enabled = true; return $this; }
    public function canBeDisabled(): static { $this->enabled = false; return $this; }
    public function describe(int $depth = 0): string
    {
        $s = str_repeat(' ', $depth) . $this->name . ($this->enabled ? '*' : '') . "\n";
        if ($this->builder) {
            foreach ($this->builder->all() as $c) {
                $s .= $c instanceof ArrayDef ? $c->describe($depth + 1) : str_repeat(' ', $depth + 1) . $c->name() . "\n";
            }
        }
        return $s;
    }
}

class NodeBuilder implements ParentDef
{
    private array $nodes = [];
    public function __construct(private ParentDef $parent) {}
    public function arrayNode(string $n): ArrayDef { return $this->nodes[] = new ArrayDef($n, $this); }
    public function scalarNode(string $n): ScalarDef { return $this->nodes[] = new ScalarDef($n, $this); }
    public function end(): ParentDef { return $this->parent; }
    public function all(): array { return $this->nodes; }
}

final class Cfg
{
    public function section(ArrayDef $root, callable $enableIf): void
    {
        $root
            ->children()
                ->arrayNode('form')
                    ->info('Form configuration')
                    ->{$enableIf('symfony/form', 'Form')}()
                    ->children()
                        ->arrayNode('csrf')
                            ->info('x')
                            ->children()
                                ->scalarNode('enabled')->end()
                                ->scalarNode('token')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }
}

$root = new ArrayDef('framework');
(new Cfg())->section($root, fn(string $pkg, string $cls): string => class_exists($cls) ? 'canBeDisabled' : 'canBeEnabled');
echo $root->describe();

$x = new SimpleXMLElement('<a><b/></a>');
echo count($x->children()), "\n";
