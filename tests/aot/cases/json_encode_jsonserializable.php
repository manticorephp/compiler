<?php
// json_encode() ignores JsonSerializable: the object encodes from its properties, jsonSerialize() is never called
final class Money implements JsonSerializable {
    public function __construct(private int $cents, private string $cur) {}
    public function jsonSerialize(): mixed { return ['amount' => $this->cents / 100, 'currency' => $this->cur]; }
}
final class Tags implements JsonSerializable {
    public function jsonSerialize(): mixed { return ['a', 'b']; }
}
final class Id implements JsonSerializable {
    public function jsonSerialize(): mixed { return 42; }
}
echo json_encode(new Money(1250, 'EUR')), "\n";
echo json_encode(new Tags()), "\n";
echo json_encode(['id' => new Id(), 'list' => [new Tags(), new Id()]]), "\n";
