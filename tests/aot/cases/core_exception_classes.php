<?php
$e = new ErrorException("m", 3, E_WARNING, "f.php", 7);
echo get_class($e), " ", $e->getMessage(), " ", $e->getCode(), " ", $e->getSeverity(), " ", $e->getFile(), ":", $e->getLine(), "\n";
$e = new ErrorException("plain");
echo $e->getSeverity(), " ", $e->getFile() === basename(__FILE__) || $e->getFile() === __FILE__ ? "file-ok" : "file-bad", " ", $e->getLine(), "\n";
$p = new ErrorException("with prev", 0, E_NOTICE, null, null, new RuntimeException("inner"));
echo $p->getPrevious()->getMessage(), " ", $p->getSeverity(), "\n";
var_dump($e instanceof Exception, $e instanceof Throwable);
var_dump(new ParseError instanceof CompileError, new CompileError instanceof Error, new UnhandledMatchError instanceof Error,
    new ClosedGeneratorException instanceof Exception, new ErrorException instanceof Exception, new ErrorException instanceof Error);
try { throw new ParseError("syntax"); } catch (CompileError $x) { echo get_class($x), ":", $x->getMessage(), "\n"; }
try { throw new ClosedGeneratorException("closed"); } catch (Exception $x) { echo get_class($x), ":", $x->getMessage(), "\n"; }
set_error_handler(function (int $no, string $str, string $file, int $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});
try { trigger_error("boom", E_USER_WARNING); } catch (ErrorException $x) { echo $x->getMessage(), " sev=", $x->getSeverity(), "\n"; }
restore_error_handler();
