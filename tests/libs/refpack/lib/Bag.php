<?php

namespace Acme;

// A typed `string[]` property whose element the LIBRARY hands to `&...$xs`:
// the reference promotes the property's element channel to cells, and the
// application — which sees only the `.sig` — must read and write it as cells.
final class Bag
{
    /** @var string[] */
    public array $names = ['a', 'b'];

    public function promote(): void { Fill::prefix('p-', $this->names[0]); }
}
