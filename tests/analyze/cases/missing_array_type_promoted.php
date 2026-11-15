<?php

final class Item {}

final class Bag
{
    /** @param int[] $byCtor */
    public function __construct(
        /** @var array<string, Item> */
        public array $inline = [],
        /** @var Item[] $named */
        private array $named = [],
        public array $byCtor = [],
        public readonly array $bare = [],   // warn
        /** Prose only, no tag. */
        protected array $prose = [],        // warn
        /** @var Item[] */
        array $notPromoted = [],            // warn: a plain param's @var is not its type
    ) {}
}
