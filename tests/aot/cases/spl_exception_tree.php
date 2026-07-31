<?php
// @epic: spl-classes
// @why: every symfony and doctrine package throws from SPL's exception tree.
//       Only 4 of the 14 were declared, so `new BadMethodCallException` was a
//       hard compile error — and the PARENTAGE matters as much as the class:
//       `catch (LogicException)` must catch a BadMethodCallException and
//       `catch (RuntimeException)` an OutOfBoundsException, or a handler
//       silently stops handling.

$classes = [
    'LogicException', 'BadFunctionCallException', 'BadMethodCallException',
    'DomainException', 'InvalidArgumentException', 'LengthException',
    'OutOfRangeException', 'RuntimeException', 'OutOfBoundsException',
    'OverflowException', 'RangeException', 'UnderflowException',
    'UnexpectedValueException', 'JsonException',
];
foreach ($classes as $c) {
    echo $c, '=', class_exists($c) ? 'yes' : 'NO', "\n";
}

// Parentage, through catch rather than instanceof — that is what a caller does.
function classify(Throwable $e): string
{
    try {
        throw $e;
    } catch (LogicException $x) {
        return 'logic';
    } catch (RuntimeException $x) {
        return 'runtime';
    } catch (Throwable $x) {
        return 'other';
    }
}

echo classify(new BadMethodCallException('m')), "\n";
echo classify(new DomainException('d')), "\n";
echo classify(new LengthException('l')), "\n";
echo classify(new InvalidArgumentException('i')), "\n";
echo classify(new OutOfBoundsException('ob')), "\n";
echo classify(new OverflowException('of')), "\n";
echo classify(new RangeException('r')), "\n";
echo classify(new UnderflowException('u')), "\n";
echo classify(new UnexpectedValueException('uv')), "\n";
echo classify(new JsonException('j')), "\n";

$e = new BadMethodCallException('gone');
var_dump($e->getMessage());
var_dump($e instanceof BadFunctionCallException);
var_dump($e instanceof LogicException);
var_dump($e instanceof RuntimeException);
