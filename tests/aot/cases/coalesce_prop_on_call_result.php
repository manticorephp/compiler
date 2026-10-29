<?php
class A { public ?string $f = 'x'; }
function g(): ?A { return null; }
function h(): ?A { return new A; }
echo g()->f ?? 'dflt', "\n";
echo h()->f ?? 'dflt', "\n";
