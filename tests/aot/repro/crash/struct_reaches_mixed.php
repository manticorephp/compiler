<?php
// #[Struct] misuse is not diagnosed: a struct reaching `mixed` (json_encode) SIGBUSes. Superset: no oracle.
#[Struct]
final class R implements JsonSerializable {
    public function __construct(public int $a) {}
    public function jsonSerialize(): mixed { return ['a' => $this->a]; }
}
echo json_encode(new R(1)), "\n";
