<?php
// Superglobals are visible in EVERY scope with no `global` declaration.
function envViaServer(): string
{
    return $_SERVER['HOME'] === getenv('HOME') ? "server=getenv" : "server!=getenv";
}
function selfIsScript(): bool
{
    return $_SERVER['PHP_SELF'] === $_SERVER['SCRIPT_NAME'];
}
function writesBack(): void
{
    $_SERVER['MC_MARK'] = 'set-in-fn';
}
echo envViaServer(), "\n";
echo selfIsScript() ? "self=script\n" : "self!=script\n";
// The request superglobals exist but stay empty under the CLI SAPI.
echo count($_GET), count($_POST), count($_COOKIE), count($_FILES), count($_REQUEST), "\n";
// A write in one scope is visible in every other: one shared cell.
writesBack();
echo $_SERVER['MC_MARK'], "\n";
$_SERVER['MC_MARK'] = 'set-at-top';
echo (function () { return $_SERVER['MC_MARK']; })(), "\n";
// $_SERVER carries the CLI argv/argc.
echo $_SERVER['argc'], " ";
echo $_SERVER['argv'][0] === $_SERVER['SCRIPT_NAME'] ? "argv0=self\n" : "argv0!=self\n";
// $GLOBALS['x'] names the top-level $x, in any scope.
$counter = 7;
function bump(): int
{
    $GLOBALS['counter'] = $GLOBALS['counter'] + 1;
    return $GLOBALS['counter'];
}
echo bump(), bump(), " ", $counter, "\n";
