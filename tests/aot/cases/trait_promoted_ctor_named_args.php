<?php
interface MappingAttribute {}
trait JCP {
    /** @param array<string, mixed> $options */
    public function __construct(
        public readonly string|null $name = null,
        public readonly string|null $referencedColumnName = null,
        public readonly bool $deferrable = false,
        public readonly bool $unique = false,
        public readonly bool|null $nullable = null,
        public readonly mixed $onDelete = null,
        public readonly string|null $columnDefinition = null,
        public readonly string|null $fieldName = null,
        public readonly array $options = [],
    ) {}
}
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class JoinColumn implements MappingAttribute { use JCP; }
class Post { #[JoinColumn(nullable: false)] private $author; #[JoinColumn(name: 'x', onDelete: 'CASCADE')] private $b; #[JoinColumn] private $c; }
foreach (['author', 'b', 'c'] as $p) {
    $a = (new ReflectionProperty('Post', $p))->getAttributes(JoinColumn::class)[0]->newInstance();
    echo $p, ': ', var_export($a->name, true), ' ', var_export($a->referencedColumnName, true), ' ', var_export($a->unique, true), ' ', var_export($a->nullable, true), ' ', var_export($a->onDelete, true), ' ', count($a->options), "\n";
}
$d = new JoinColumn(nullable: false); echo var_export($d->referencedColumnName, true), var_export($d->nullable, true), "\n";
