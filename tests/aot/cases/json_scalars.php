<?php

final class Json {
    private string $s;
    private int $i;
    private int $n;

    public function __construct(string $s) {
        $this->s = $s;
        $this->i = 0;
        $this->n = \strlen($s);
    }

    public function decode(): mixed {
        $this->ws();
        return $this->value();
    }

    private function ws(): void {
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === ' ' || $c === "\n" || $c === "\t" || $c === "\r") {
                $this->i = $this->i + 1;
            } else {
                return;
            }
        }
    }

    private function value(): mixed {
        $c = $this->s[$this->i];
        if ($c === '"') { return $this->str(); }
        if ($c === 't') { $this->i = $this->i + 4; return true; }
        if ($c === 'f') { $this->i = $this->i + 5; return false; }
        if ($c === 'n') { $this->i = $this->i + 4; return null; }
        return $this->num();
    }

    private function str(): string {
        $this->i = $this->i + 1; // opening quote
        $out = '';
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === '"') { $this->i = $this->i + 1; return $out; }
            $out = $out . $c;
            $this->i = $this->i + 1;
        }
        return $out;
    }

    private function num(): mixed {
        $start = $this->i;
        $isFloat = false;
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === '-' || $c === '+' || ($c >= '0' && $c <= '9')) {
                $this->i = $this->i + 1;
            } elseif ($c === '.' || $c === 'e' || $c === 'E') {
                $isFloat = true;
                $this->i = $this->i + 1;
            } else {
                break;
            }
        }
        $tok = \substr($this->s, $start, $this->i - $start);
        if ($isFloat) { return (float)$tok; }
        return (int)$tok;
    }
}

function decode(string $s): mixed {
    $j = new Json($s);
    return $j->decode();
}

echo decode("42"), "\n";
echo decode("3.5"), "\n";
echo decode("true"), "\n";
echo decode("false"), "\n";
echo decode("null"), "\n";
echo decode("  \"hello\"  "), "\n";
