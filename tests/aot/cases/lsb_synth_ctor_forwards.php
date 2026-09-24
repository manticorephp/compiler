<?php
namespace App\Fixer;
interface ConfigurableI { public function configure(array $c): void; }
trait ConfigurableTrait {
    protected ?array $configuration = null;
    public function configure(array $c): void { $this->configuration = $c + ['tags' => ['x']]; $this->post(); }
    protected function post(): void {}
}
abstract class AbstractFixer {
    protected string $name;
    public function __construct() {
        $parts = explode('\\', static::class);
        $this->name = strtolower(substr(end($parts), 0, -\strlen('Fixer')));
        if ($this instanceof ConfigurableI) { $this->configure([]); }
    }
    public function getName(): string { return $this->name; }
}
abstract class AbstractProxyFixer extends AbstractFixer {
    /** @var array<string, AbstractFixer> */
    protected array $proxyFixers = [];
    public function __construct() {
        $proxyFixers = [];
        foreach ($this->createProxyFixers() as $p) { $proxyFixers[$p->getName()] = $p; }
        $this->proxyFixers = $proxyFixers;
        parent::__construct();
    }
    /** @return list<AbstractFixer> */
    abstract protected function createProxyFixers(): array;
}
final class RenameFixer extends AbstractFixer implements ConfigurableI { use ConfigurableTrait; }
final class RemoveFixer extends AbstractFixer {}
final class TagCasingFixer extends AbstractProxyFixer implements ConfigurableI {
    use ConfigurableTrait;
    protected function post(): void { $this->proxyFixers['rename']->configure(['tags' => $this->configuration['tags']]); }
    protected function createProxyFixers(): array { return [new RenameFixer()]; }
}
final class NoAccessFixer extends AbstractProxyFixer {
    protected function createProxyFixers(): array { return [new RemoveFixer()]; }
}
foreach ([TagCasingFixer::class, NoAccessFixer::class, RenameFixer::class] as $c) { $f = new $c(); echo $f->getName(), "\n"; }
