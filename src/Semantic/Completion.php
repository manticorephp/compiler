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
    ) {
        if ($this->insertText === '') { $this->insertText = $label; }
    }
}
