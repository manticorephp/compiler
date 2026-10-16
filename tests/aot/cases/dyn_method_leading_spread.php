<?php
class T0 { public function get(string $h, string $k, int $d = 7): string { return "T0.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 0 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.0 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.0 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.0 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.0 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.0 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.0 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.0 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.0 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.0 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.0 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.0 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.0 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.0 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.0 $h $a"; }
 public function ping(string $h): string { return "pong0 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom0 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m0 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T1 { public function get(string $h, string $k, int $d = 7): string { return "T1.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 1 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.1 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.1 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.1 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.1 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.1 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.1 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.1 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.1 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.1 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.1 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.1 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.1 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.1 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.1 $h $a"; }
 public function ping(string $h): string { return "pong1 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom1 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m1 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T2 { public function get(string $h, string $k, int $d = 7): string { return "T2.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 2 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.2 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.2 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.2 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.2 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.2 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.2 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.2 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.2 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.2 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.2 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.2 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.2 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.2 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.2 $h $a"; }
 public function ping(string $h): string { return "pong2 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom2 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m2 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T3 { public function get(string $h, string $k, int $d = 7): string { return "T3.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 3 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.3 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.3 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.3 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.3 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.3 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.3 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.3 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.3 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.3 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.3 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.3 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.3 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.3 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.3 $h $a"; }
 public function ping(string $h): string { return "pong3 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom3 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m3 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T4 { public function get(string $h, string $k, int $d = 7): string { return "T4.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 4 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.4 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.4 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.4 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.4 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.4 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.4 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.4 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.4 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.4 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.4 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.4 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.4 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.4 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.4 $h $a"; }
 public function ping(string $h): string { return "pong4 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom4 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m4 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T5 { public function get(string $h, string $k, int $d = 7): string { return "T5.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 5 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.5 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.5 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.5 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.5 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.5 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.5 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.5 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.5 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.5 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.5 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.5 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.5 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.5 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.5 $h $a"; }
 public function ping(string $h): string { return "pong5 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom5 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m5 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T6 { public function get(string $h, string $k, int $d = 7): string { return "T6.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 6 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.6 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.6 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.6 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.6 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.6 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.6 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.6 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.6 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.6 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.6 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.6 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.6 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.6 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.6 $h $a"; }
 public function ping(string $h): string { return "pong6 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom6 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m6 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T7 { public function get(string $h, string $k, int $d = 7): string { return "T7.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 7 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.7 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.7 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.7 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.7 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.7 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.7 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.7 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.7 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.7 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.7 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.7 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.7 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.7 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.7 $h $a"; }
 public function ping(string $h): string { return "pong7 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom7 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m7 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T8 { public function get(string $h, string $k, int $d = 7): string { return "T8.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 8 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.8 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.8 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.8 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.8 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.8 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.8 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.8 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.8 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.8 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.8 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.8 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.8 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.8 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.8 $h $a"; }
 public function ping(string $h): string { return "pong8 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom8 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m8 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T9 { public function get(string $h, string $k, int $d = 7): string { return "T9.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 9 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.9 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.9 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.9 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.9 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.9 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.9 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.9 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.9 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.9 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.9 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.9 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.9 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.9 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.9 $h $a"; }
 public function ping(string $h): string { return "pong9 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom9 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m9 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T10 { public function get(string $h, string $k, int $d = 7): string { return "T10.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 10 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.10 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.10 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.10 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.10 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.10 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.10 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.10 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.10 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.10 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.10 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.10 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.10 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.10 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.10 $h $a"; }
 public function ping(string $h): string { return "pong10 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom10 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m10 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T11 { public function get(string $h, string $k, int $d = 7): string { return "T11.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 11 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.11 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.11 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.11 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.11 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.11 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.11 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.11 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.11 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.11 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.11 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.11 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.11 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.11 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.11 $h $a"; }
 public function ping(string $h): string { return "pong11 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom11 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m11 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T12 { public function get(string $h, string $k, int $d = 7): string { return "T12.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 12 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.12 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.12 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.12 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.12 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.12 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.12 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.12 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.12 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.12 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.12 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.12 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.12 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.12 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.12 $h $a"; }
 public function ping(string $h): string { return "pong12 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom12 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m12 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T13 { public function get(string $h, string $k, int $d = 7): string { return "T13.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 13 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.13 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.13 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.13 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.13 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.13 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.13 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.13 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.13 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.13 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.13 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.13 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.13 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.13 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.13 $h $a"; }
 public function ping(string $h): string { return "pong13 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom13 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m13 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T14 { public function get(string $h, string $k, int $d = 7): string { return "T14.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 14 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.14 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.14 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.14 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.14 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.14 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.14 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.14 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.14 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.14 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.14 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.14 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.14 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.14 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.14 $h $a"; }
 public function ping(string $h): string { return "pong14 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom14 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m14 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T15 { public function get(string $h, string $k, int $d = 7): string { return "T15.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 15 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.15 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.15 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.15 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.15 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.15 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.15 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.15 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.15 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.15 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.15 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.15 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.15 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.15 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.15 $h $a"; }
 public function ping(string $h): string { return "pong15 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom15 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m15 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T16 { public function get(string $h, string $k, int $d = 7): string { return "T16.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 16 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.16 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.16 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.16 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.16 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.16 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.16 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.16 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.16 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.16 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.16 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.16 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.16 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.16 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.16 $h $a"; }
 public function ping(string $h): string { return "pong16 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom16 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m16 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T17 { public function get(string $h, string $k, int $d = 7): string { return "T17.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 17 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.17 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.17 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.17 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.17 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.17 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.17 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.17 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.17 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.17 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.17 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.17 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.17 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.17 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.17 $h $a"; }
 public function ping(string $h): string { return "pong17 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom17 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m17 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T18 { public function get(string $h, string $k, int $d = 7): string { return "T18.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 18 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.18 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.18 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.18 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.18 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.18 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.18 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.18 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.18 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.18 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.18 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.18 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.18 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.18 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.18 $h $a"; }
 public function ping(string $h): string { return "pong18 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom18 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m18 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class T19 { public function get(string $h, string $k, int $d = 7): string { return "T19.get $h $k $d"; }
 public function set(string $h, int $a, int $b = 2): int { return 19 + $a + $b; }
 public function x0(string $h, int $a = 0): string { return "x0.19 $h $a"; }
 public function x1(string $h, int $a = 0): string { return "x1.19 $h $a"; }
 public function x2(string $h, int $a = 0): string { return "x2.19 $h $a"; }
 public function x3(string $h, int $a = 0): string { return "x3.19 $h $a"; }
 public function x4(string $h, int $a = 0): string { return "x4.19 $h $a"; }
 public function x5(string $h, int $a = 0): string { return "x5.19 $h $a"; }
 public function x6(string $h, int $a = 0): string { return "x6.19 $h $a"; }
 public function x7(string $h, int $a = 0): string { return "x7.19 $h $a"; }
 public function x8(string $h, int $a = 0): string { return "x8.19 $h $a"; }
 public function x9(string $h, int $a = 0): string { return "x9.19 $h $a"; }
 public function x10(string $h, int $a = 0): string { return "x10.19 $h $a"; }
 public function x11(string $h, int $a = 0): string { return "x11.19 $h $a"; }
 public function x12(string $h, int $a = 0): string { return "x12.19 $h $a"; }
 public function x13(string $h, int $a = 0): string { return "x13.19 $h $a"; }
 public function ping(string $h): string { return "pong19 $h"; }
 public function boom(string $h, string $m): string { throw new RuntimeException("boom19 $h $m"); }
 public function many(string $h, float $x, bool $f, ?string $s = null, array $z = []): string { return "m19 $h $x " . var_export($f, true) . " " . ($s ?? 'null') . count($z); }
}
class Proxy { public function __construct(private $t, private string $host) {}
  public function __call(string $method, array $args) { return $this->t->{$method}($this->host, ...$args); } }
function mk(int $i) { $c = "T$i"; return new $c(); }
$tests = [['get',['k']],['get',['k',9]],['set',[1]],['set',[1,5]],['ping',[]],['boom',['x']],['many',[1.5,true]],['many',[2,false,'s',[1,2]]],['nope',[]]];
foreach ([0, 5, 59 % 20] as $i) { $p = new Proxy(mk($i), "h$i");
  foreach ($tests as [$m,$a]) { try { echo $m, ': ', var_export($p->$m(...$a), true), "\n"; } catch (\Throwable $e) { echo get_class($e), $e instanceof ArgumentCountError ? "" : ": " . $e->getMessage(), "\n"; } } }