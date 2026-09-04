<?php
// @epic: element-repr
// @why:  the shape every "pick one of these two collections" accessor has; found
//        while adding Class::${expr}, whose read chain lowers to exactly this.

// A function DECLARED `array` whose body returns a TERNARY over two concrete
// arrays hands the caller a RAW POINTER typed `unknown`, printed as an int.
//
// Nothing dynamic is involved. The single-return spelling right below is
// correct, and it is correct for the same static properties, so this is not the
// static channel either: it is that NarrowReturns narrows `-> unknown` to the
// body's real type for a plain return and does NOT look through a ternary, so
// the function keeps `unknown` while the value it returns stays a raw vec. The
// return path then boxes nothing and the caller reads the pointer as a cell.
//
// Annotating the return (`@return array<string,string>`) makes it correct, which
// is the same discriminator the rest of the erasure family has: a TYPED channel
// was always right, and the bare one is `mixed`.

class R
{
    private static array $a = [];
    private static array $b = [];

    public static function put(string $k, string $v): void { self::$a[$k] = $v; }

    /** The broken one: bare `array` return, ternary body. */
    public static function pick(string $p): array { return $p === 'a' ? self::$a : self::$b; }

    /** CONTROL: bare `array` return, single return. Correct today. */
    public static function justA(): array { return self::$a; }

    /** CONTROL: the same ternary with the element type annotated. Correct today.
     *  @return array<string,string> */
    public static function pickTyped(string $p): array { return $p === 'a' ? self::$a : self::$b; }
}

R::put('x', 'X');

var_dump(R::justA());       // array(1) { ["x"]=> string(1) "X" }  — correct
var_dump(R::pickTyped('a'));// array(1) { ["x"]=> string(1) "X" }  — correct
var_dump(R::pick('a'));     // php: the same array   manticore: int(<pointer>)

// A LOCAL array through the same shape, to show the static property is
// incidental — what matters is the bare-`array` return over a ternary.
function pickLocal(string $p): array
{
    $one = ['k' => 'v'];
    $two = ['j' => 'w'];
    return $p === 'a' ? $one : $two;
}

var_dump(pickLocal('a'));
