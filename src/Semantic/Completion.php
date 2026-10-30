<?php

namespace Semantic;

/** A completion candidate. $kind: method | function | field | variable | class | interface | enum | enumMember | constant. */
final class Completion
{
    public function __construct(
        public string $label,
        public string $kind,
        public string $detail = '',
        public string $insertText = '',
        /** 0 own member / variable, 1 inherited member, 2 global name */
        public int $rank = 2,
        /** FQN to import when the item is accepted; 0 = no import */
        public ?string $useFqn = null,
        public int $useLine = 0,
    ) {
        if ($this->insertText === '') { $this->insertText = $label; }
    }
}
