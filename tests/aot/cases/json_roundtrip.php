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
        $this->ws();
        $c = $this->s[$this->i];
        if ($c === '"') { return $this->str(); }
        if ($c === '{') { return $this->obj(); }
        if ($c === '[') { return $this->arr(); }
        if ($c === 't') { $this->i = $this->i + 4; return true; }
        if ($c === 'f') { $this->i = $this->i + 5; return false; }
        if ($c === 'n') { $this->i = $this->i + 4; return null; }
        return $this->num();
    }

    private function obj(): mixed {
        $this->i = $this->i + 1; // {
        $o = new stdClass();
        $this->ws();
        if ($this->s[$this->i] === '}') { $this->i = $this->i + 1; return $o; }
        while (true) {
            $this->ws();
            $key = $this->str();
            $this->ws();
            $this->i = $this->i + 1; // :
            $o->$key = $this->value();
            $this->ws();
            $c = $this->s[$this->i];
            if ($c === ',') { $this->i = $this->i + 1; continue; }
            $this->i = $this->i + 1; // }
            return $o;
        }
    }

    private function arr(): mixed {
        $this->i = $this->i + 1; // [
        $out = [];
        $this->ws();
        if ($this->s[$this->i] === ']') { $this->i = $this->i + 1; return $out; }
        while (true) {
            $out[] = $this->value();
            $this->ws();
            $c = $this->s[$this->i];
            if ($c === ',') { $this->i = $this->i + 1; continue; }
            $this->i = $this->i + 1; // ]
            return $out;
        }
    }

    private function str(): string {
        $this->i = $this->i + 1;
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

$j = new Json('{"name": "Ada", "age": 36, "active": true, "tags": [1, 2, 3]}');
$o = $j->decode();
echo $o->name, "/", $o->age, "/", $o->active, "\n";
echo count($o->tags), ":";
foreach ($o->tags as $t) { echo $t, ","; }
echo "\n";

function enc(mixed $v): string {
    if (is_null($v)) { return "null"; }
    if (is_bool($v)) { $s = (string)$v; return $s === "1" ? "true" : "false"; }
    if (is_int($v)) { return (string)$v; }
    if (is_float($v)) { return (string)$v; }
    if (is_string($v)) { return '"' . $v . '"'; }
    if (is_object($v)) {
        $out = "{"; $first = true;
        foreach ((array)$v as $k => $val) {
            if (!$first) { $out = $out . ","; }
            $first = false;
            $out = $out . '"' . $k . '":' . enc($val);
        }
        return $out . "}";
    }
    $out = "["; $first = true;
    foreach ($v as $val) {
        if (!$first) { $out = $out . ","; }
        $first = false;
        $out = $out . enc($val);
    }
    return $out . "]";
}

$src = '{"name":"Ada","age":36,"active":true,"tags":[1,2,3]}';
$j2 = new Json($src);
echo enc($j2->decode()), "\n";
