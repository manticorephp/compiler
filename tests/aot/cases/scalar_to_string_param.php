<?php
// A scalar passed where a string is expected is rendered (coercive mode),
// for codegen builtins and stdlib functions alike (symfony ProgressBar:
// str_pad($bar->getProgress(), …)).
final class Bar {
    private int $step = 3;
    public function getProgress(): int { return 7; }
    private function getStepWidth(): int { return 4; }
    public static function fmts(): array {
        return ['current' => static fn (self $bar) => str_pad($bar->getProgress(), $bar->getStepWidth(), ' ', \STR_PAD_LEFT)];
    }
}
$f = Bar::fmts()['current'];
var_dump($f(new Bar()));
var_dump(str_pad(7, 4, '*', STR_PAD_LEFT));
var_dump(strlen(12345), strtoupper(5), str_repeat(7, 2), str_pad(1.5, 5, "_"), ucfirst(3), strlen(true));
for ($i = 0; $i < 3; $i++) { echo str_pad($i, 3, "0", STR_PAD_LEFT), " "; } echo "
";
