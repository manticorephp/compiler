<?php

// A user save handler: session_set_save_handler(object). The store is in
// memory, so what this asserts is the PROTOCOL — which methods the extension
// calls, in what order, with what arguments — not the files backend.
//
// ⚠ Nothing prints until the end: php's CLI marks the headers sent at the
// cache-limiter headers session_start() emits. Results accumulate into a string
// rather than an array because a heterogeneous collection array erases its
// element type here.

class MemHandler implements SessionHandlerInterface
{
    /** @var array<string,string> */
    public static array $store = [];

    public static string $log = '';

    public function open(string $path, string $name): bool
    {
        self::$log .= "open(" . $name . ");";
        return true;
    }

    public function close(): bool
    {
        self::$log .= "close;";
        return true;
    }

    public function read(string $id): string
    {
        self::$log .= "read(" . $id . ");";
        if (isset(self::$store[$id])) {
            return self::$store[$id];
        }
        return "";
    }

    public function write(string $id, string $data): bool
    {
        self::$log .= "write(" . $id . ");";
        self::$store[$id] = $data;
        return true;
    }

    public function destroy(string $id): bool
    {
        self::$log .= "destroy(" . $id . ");";
        self::$store[$id] = "";
        return true;
    }

    public function gc(int $max_lifetime): int
    {
        self::$log .= "gc(" . $max_lifetime . ");";
        return 0;
    }
}

ini_set("session.use_cookies", "0");
session_set_save_handler(new MemHandler(), false);
session_id("memone");

$out = "";
$out .= var_export(session_start(), true) . "\n";
$out .= var_export(session_status(), true) . "\n";
$_SESSION["k"] = "v";
$_SESSION["n"] = 7;
$out .= var_export(session_write_close(), true) . "\n";
$out .= var_export(MemHandler::$store["memone"], true) . "\n";

$_SESSION = [];
$out .= var_export(session_start(), true) . "\n";
$out .= var_export($_SESSION["k"], true) . "\n";
$out .= var_export($_SESSION["n"], true) . "\n";
$out .= var_export(session_destroy(), true) . "\n";
$out .= var_export(MemHandler::$store["memone"], true) . "\n";
$out .= MemHandler::$log . "\n";

echo $out;
