<?php
// A dynamic method call whose receiver and argument are fresh calls, over many candidate classes and one by-ref name.
class Proto {
    public array $log = [];
    public function setK0(mixed $v): static { $this->log[] = 'k0=' . (string)$v; return $this; }
    public function setK1(mixed $v): static { $this->log[] = 'k1=' . (string)$v; return $this; }
    public function setK2(mixed $v): static { $this->log[] = 'k2=' . (string)$v; return $this; }
}
class C0 extends Proto { public function setAx0(mixed $v): static { return $this; } public function setBx0(mixed $v): static { return $this; } }
class C1 extends Proto { public function setAx1(mixed $v): static { return $this; } public function setBx1(mixed $v): static { return $this; } }
class C2 extends Proto { public function setAx2(mixed $v): static { return $this; } public function setBx2(mixed $v): static { return $this; } }
class C3 extends Proto { public function setAx3(mixed $v): static { return $this; } public function setBx3(mixed $v): static { return $this; } }
class C4 extends Proto { public function setAx4(mixed $v): static { return $this; } public function setBx4(mixed $v): static { return $this; } }
class C5 extends Proto { public function setAx5(mixed $v): static { return $this; } public function setBx5(mixed $v): static { return $this; } }
class C6 extends Proto { public function setAx6(mixed $v): static { return $this; } public function setBx6(mixed $v): static { return $this; } }
class C7 extends Proto { public function setAx7(mixed $v): static { return $this; } public function setBx7(mixed $v): static { return $this; } }
class C8 extends Proto { public function setAx8(mixed $v): static { return $this; } public function setBx8(mixed $v): static { return $this; } }
class C9 extends Proto { public function setAx9(mixed $v): static { return $this; } public function setBx9(mixed $v): static { return $this; } }
class C10 extends Proto { public function setAx10(mixed $v): static { return $this; } public function setBx10(mixed $v): static { return $this; } }
class C11 extends Proto { public function setAx11(mixed $v): static { return $this; } public function setBx11(mixed $v): static { return $this; } }
class C12 extends Proto { public function setAx12(mixed $v): static { return $this; } public function setBx12(mixed $v): static { return $this; } }
class C13 extends Proto { public function setAx13(mixed $v): static { return $this; } public function setBx13(mixed $v): static { return $this; } }
class C14 extends Proto { public function setAx14(mixed $v): static { return $this; } public function setBx14(mixed $v): static { return $this; } }
class C15 extends Proto { public function setAx15(mixed $v): static { return $this; } public function setBx15(mixed $v): static { return $this; } }
class C16 extends Proto { public function setAx16(mixed $v): static { return $this; } public function setBx16(mixed $v): static { return $this; } }
class C17 extends Proto { public function setAx17(mixed $v): static { return $this; } public function setBx17(mixed $v): static { return $this; } }
class C18 extends Proto { public function setAx18(mixed $v): static { return $this; } public function setBx18(mixed $v): static { return $this; } }
class C19 extends Proto { public function setAx19(mixed $v): static { return $this; } public function setBx19(mixed $v): static { return $this; } }
class C20 extends Proto { public function setAx20(mixed $v): static { return $this; } public function setBx20(mixed $v): static { return $this; } }
class C21 extends Proto { public function setAx21(mixed $v): static { return $this; } public function setBx21(mixed $v): static { return $this; } }
class C22 extends Proto { public function setAx22(mixed $v): static { return $this; } public function setBx22(mixed $v): static { return $this; } }
class C23 extends Proto { public function setAx23(mixed $v): static { return $this; } public function setBx23(mixed $v): static { return $this; } }
class C24 extends Proto { public function setAx24(mixed $v): static { return $this; } public function setBx24(mixed $v): static { return $this; } }
class C25 extends Proto { public function setAx25(mixed $v): static { return $this; } public function setBx25(mixed $v): static { return $this; } }
class C26 extends Proto { public function setAx26(mixed $v): static { return $this; } public function setBx26(mixed $v): static { return $this; } }
class C27 extends Proto { public function setAx27(mixed $v): static { return $this; } public function setBx27(mixed $v): static { return $this; } }
class C28 extends Proto { public function setAx28(mixed $v): static { return $this; } public function setBx28(mixed $v): static { return $this; } }
class C29 extends Proto { public function setAx29(mixed $v): static { return $this; } public function setBx29(mixed $v): static { return $this; } }
class C30 extends Proto { public function setAx30(mixed $v): static { return $this; } public function setBx30(mixed $v): static { return $this; } }
class C31 extends Proto { public function setAx31(mixed $v): static { return $this; } public function setBx31(mixed $v): static { return $this; } }
class C32 extends Proto { public function setAx32(mixed $v): static { return $this; } public function setBx32(mixed $v): static { return $this; } }
class C33 extends Proto { public function setAx33(mixed $v): static { return $this; } public function setBx33(mixed $v): static { return $this; } }
class C34 extends Proto { public function setAx34(mixed $v): static { return $this; } public function setBx34(mixed $v): static { return $this; } }
class C35 extends Proto { public function setAx35(mixed $v): static { return $this; } public function setBx35(mixed $v): static { return $this; } }
class C36 extends Proto { public function setAx36(mixed $v): static { return $this; } public function setBx36(mixed $v): static { return $this; } }
class C37 extends Proto { public function setAx37(mixed $v): static { return $this; } public function setBx37(mixed $v): static { return $this; } }
class C38 extends Proto { public function setAx38(mixed $v): static { return $this; } public function setBx38(mixed $v): static { return $this; } }
class C39 extends Proto { public function setAx39(mixed $v): static { return $this; } public function setBx39(mixed $v): static { return $this; } }
class Swap { public function setK2(mixed &$v): static { $v = 'swapped'; return $this; } }
class Cl { public function clone(): int { return 7; } }
function apply(mixed $proto, array $keys): mixed {
    $mk = static fn () => clone $proto;
    $cloner = new Cl();
    $last = null;
    foreach ($keys as $key) {
        $last = $mk()->{'set' . $key}($cloner->clone());
    }
    return $last;
}
echo implode(',', apply(new C3(), ['K0', 'K1', 'K2'])->log), "\n";
echo implode(',', apply(new C39(), ['K2'])->log), "\n";
