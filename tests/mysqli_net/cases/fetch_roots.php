<?php
// fetch_object's class argument spelled so a source scan cannot read it as a plain class name: a `use` alias, static::class /
// self::class (a private property inherited from a parent: tests/aot/repro/wrong/parent_private_write_from_child_scope.php, #475). (A differently-cased call, Fetch_Object(, needs php's case-insensitive method lookup: #473.)
namespace App\Models {
    class Row { public $id; public $a; }
    class Other { public $id; public $a; }
}
namespace {
    use App\Models\Row as R;
    use App\Models as M;

    class Host
    {
        public static function viaStatic(mysqli_result $r) { return $r->fetch_object(static::class); }
        public static function viaSelf(mysqli_result $r) { return $r->fetch_object(self::class); }
        public $id;
        public $a;
    }
    final class Host2 extends Host {}
    function show(string $l, mixed $v): void
    {
        echo $l, ': ';
        echo print_r($v, true), "\n";
    }
    $m = new mysqli(getenv('MYSQLI_HOST'), getenv('MYSQLI_USER'), getenv('MYSQLI_PASS'), getenv('MYSQLI_DB'), (int) getenv('MYSQLI_PORT'));
    $q = 'SELECT 1 AS id, \'x\' AS a';
    show('alias', $m->query($q)->fetch_object(R::class));
    show('namespace alias', $m->query($q)->fetch_object(M\Other::class));
    show('alias string', $m->query($q)->fetch_object('App\Models\Row'));
    show('static', Host2::viaStatic($m->query($q)));
    show('self', Host2::viaSelf($m->query($q)));
    show('proc', mysqli_fetch_object($m->query($q), R::class));
}
