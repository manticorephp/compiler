<?php
// ext/intl UConverter over ICU: conversions both ways, substitution characters, encodings and
// types, aliases/standards, transcode with options, errors, and a subclass whose toUCallback /
// fromUCallback ICU calls back into (reasons, sources, code points, the by-ref error).
$c = new UConverter("ascii", "utf-8");
var_dump($c->convert("héllo"), $c->convert("abc", true), $c->getSubstChars(), $c->getSourceType(), $c->getDestinationType(),
    $c->getSourceEncoding(), $c->getDestinationEncoding(), $c->getErrorCode(), $c->getErrorMessage());
$u = new UConverter("utf-16le", "iso-8859-5");
var_dump(bin2hex($u->convert("\xb0\xb1")), bin2hex($u->convert("\x10\x04\x11\x04", true)));
var_dump($c->setSubstChars("#"), $c->convert("€x"), $c->getSubstChars(), $c->setSubstChars(""), $c->getErrorMessage());
var_dump($c->setDestinationEncoding("windows-1251"), $c->convert("Привіт"), $c->getDestinationEncoding(), $c->setSourceEncoding("nope"), $c->getErrorCode(), $c->getErrorMessage(), intl_get_error_message());
var_dump(UConverter::transcode("x\xffy", "utf-16be", "utf-8"), bin2hex(UConverter::transcode("€", "iso-8859-1", "utf-8", ["to_subst" => "?"])),
    UConverter::transcode("a", "nope", "utf-8"), intl_get_error_message(), UConverter::transcode("€", "shift_jis", "utf-8"));
var_dump(UConverter::getAliases("latin1"), UConverter::getAliases("zzz"), count(UConverter::getStandards()), count(UConverter::getAvailable()) > 100,
    UConverter::reasonText(UConverter::REASON_ILLEGAL));
try { UConverter::reasonText(9); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
try { new UConverter("nope"); } catch (IntlException $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
class MyC extends UConverter
{
    public function toUCallback(int $reason, string $source, string $codeUnits, &$error): string|int|array|null
    {
        echo "to: ", $reason, " ", bin2hex($source), " ", bin2hex($codeUnits), " ", $error, "\n";
        if ($reason === UConverter::REASON_ILLEGAL) { $error = 0; return ["<", 0x1F600, ">"]; }
        return null;
    }
    public function fromUCallback(int $reason, array $source, int $codePoint, &$error): string|int|array|null
    {
        echo "from: ", $reason, " ", json_encode($source), " ", $codePoint, " ", $error, "\n";
        if ($reason === UConverter::REASON_UNASSIGNED) { $error = 0; return "[" . dechex($codePoint) . "]"; }
        return null;
    }
}
$m = new MyC("ascii", "utf-8");
var_dump($m->convert("a\xffbé€z😀"));
$k = clone $c;
var_dump($k->convert("é"), $k->getDestinationEncoding());
unset($m, $k);
class Base extends UConverter {}
$b = new Base("ascii", "utf-8");
var_dump($b->convert("é!"));
unset($b);
echo "done\n";
