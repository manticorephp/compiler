<?php
final class Resolver {
    /** @param array<string, mixed> $c
     *  @return array<string, mixed> */
    public function resolve(array $c): array { return $c + ['strategy' => 'enforce', 'preserve_existing_declaration' => true]; }
}
/**
 * @template TIn of array<string, mixed>
 * @template TOut of array<string, mixed>
 */
trait Conf {
    /** @var null|TOut */
    protected ?array $configuration = null;
    /** @param TIn $c */
    final public function configure(array $c): void {
        $this->configuration = (new Resolver())->resolve($c);
        $this->post();
    }
    protected function post(): void {}
}
/**
 * @phpstan-type _In array{preserve_existing_declaration?: bool, strategy?: 'add_when_missing'|'enforce'|'remove'}
 * @phpstan-type _Out array{strategy: 'add_when_missing'|'enforce'|'remove'}
 */
final class Fixer {
    /** @use Conf<_In, _Out> */
    use Conf;
    protected function post(): void {
        if ('enforce' === $this->configuration['strategy'] && true === $this->configuration['preserve_existing_declaration']) {
            $this->configuration['strategy'] = 'add_when_missing';
        }
        unset($this->configuration['preserve_existing_declaration']);
    }
    public function dump(): void { var_dump($this->configuration); }
}
$f = new Fixer();
$f->configure([]); $f->dump();
$f->configure(['strategy' => 'remove']); $f->dump();
