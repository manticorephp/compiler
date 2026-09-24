<?php

// An arm its own type guard rules out never runs, so its arguments are not
// checked against the operand's (contradicting) static type.
final class In
{
    /** @param array<string, string> $params */
    public function __construct(private array $params) {}

    private function esc(string $t): string { return "'" . $t . "'"; }

    public function __toString(): string
    {
        $out = [];
        foreach ($this->params as $k => $v) {
            $out[] = $k . '=' . (\is_array($v) ? implode(' ', array_map($this->esc(...), $v)) : $this->esc($v));
            if (!\is_string($v)) { $out[] = count($v); }
        }
        return implode(' ', $out);
    }
}
echo new In(['a' => 'x', 'b' => 'y z']), "\n";
