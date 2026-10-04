<?php
// SplFileInfo::openFile() hands back a php-faithful SplFileObject. It used to be
// missing, so a call on a plain SplFileInfo dispatched to the one subclass that
// declared it (php-cs-fixer's StdinFileInfo, which throws).

final class StdinInfo extends \SplFileInfo
{
    public function __construct() { parent::__construct(__FILE__); }
    public function openFile($m = 'r', $u = false, $c = null): \SplFileObject
    {
        throw new \BadMethodCallException('StdinInfo::openFile');
    }
}

final class Holder
{
    public function __construct(private \SplFileInfo $f) {}
    public function first(): string { return rtrim((string) $this->f->openFile('r')->fgets()); }
}

if ($argc > 5) { new StdinInfo(); }
echo (new Holder(new \SplFileInfo(__FILE__)))->first(), "\n";
try { (new StdinInfo())->openFile(); } catch (\BadMethodCallException $e) { echo $e->getMessage(), "\n"; }

$p = sys_get_temp_dir() . '/sfo_' . getmypid() . '.txt';
file_put_contents($p, "one\ntwo\r\n\nfour");
$f = new SplFileObject($p);
var_dump($f->fgets(), $f->key(), $f->fgets(), $f->key(), $f->fgets(), $f->fgets(), $f->eof());
try { var_dump($f->fgets()); } catch (\RuntimeException $e) { echo get_class($e), ': ', str_replace($p, 'P', $e->getMessage()), "\n"; }
$f->rewind();
var_dump((string)$f, $f->key(), $f->current());
foreach ($f as $k => $line) { var_dump($k, $line); }
$f->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
foreach ($f as $k => $line) { var_dump($k, $line); }
$f->setFlags(0);
$f->seek(2); var_dump($f->key(), $f->current());
$f->seek(100); var_dump($f->key(), $f->current(), $f->valid());
$f->rewind(); var_dump($f->fgetc(), $f->fgetc(), $f->fgetc(), $f->fgetc(), $f->key(), $f->ftell());
var_dump($f->fseek(0), $f->fread(5), $f->getSize(), $f->getFilename() === basename($p));
$w = (new SplFileInfo($p))->openFile('r+');
var_dump(get_class($w), $w->flock(LOCK_EX), $w->ftruncate(0), $w->fwrite("a,b,\"c d\"\n1,2,3\n"), $w->fwrite("xyz", 2), $w->fflush());
$w->rewind(); var_dump($w->fgetcsv(',', '"', '\\'), $w->fgetcsv(',', '"', '\\'), $w->fgetcsv(',', '"', '\\'), $w->fgetcsv(',', '"', '\\'));
$w->setCsvControl(',', '"', '\\'); $w->setFlags(SplFileObject::READ_CSV); $w->rewind(); foreach ($w as $k => $row) { echo $k, '=', json_encode($row), "\n"; }
var_dump($w->getCsvControl(), $w->hasChildren(), $w->getChildren(), $w->getFlags(), $w->getMaxLineLen());
$w->setMaxLineLen(2); $w->setFlags(0); $w->rewind(); var_dump($w->fgets(), $w->fgets());
var_dump($w->fputcsv(['q', 'r s'], ',', '"', '\\'), $w->fstat()['size']);
unset($w, $f);
try { new SplFileObject($p . '.missing'); } catch (\RuntimeException $e) { echo get_class($e), ': ', str_replace($p, 'P', $e->getMessage()), "\n"; }
try { new SplFileObject(sys_get_temp_dir()); } catch (\LogicException $e) { echo get_class($e), ': ', str_replace(sys_get_temp_dir(), 'T', $e->getMessage()), "\n"; }
$t = new SplTempFileObject();
var_dump($t->getPathname(), $t->getFilename(), $t->fwrite("hello\nworld\n"));
$t->rewind(); foreach ($t as $k => $l) { var_dump($k, $l); }
var_dump($t instanceof SplFileObject, $t instanceof SplFileInfo, $t instanceof SeekableIterator, $t instanceof RecursiveIterator);
unlink($p);
