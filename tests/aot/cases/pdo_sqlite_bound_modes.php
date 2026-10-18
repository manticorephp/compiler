<?php
$d = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$q="select '' as e, 5 as i, null as n, '' as e2";
function run($d,$q,$types){ $s=$d->prepare($q); $v=[]; foreach($types as $c=>$t){ $v[$c]=null; $s->bindColumn($c,$v[$c],$t);} $s->execute(); $s->fetch(PDO::FETCH_BOUND); echo json_encode($v),"\n"; }
foreach ([[0,false],[1,false],[2,false],[0,true]] as [$o,$st]) { $d->setAttribute(PDO::ATTR_ORACLE_NULLS,$o); $d->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,$st); echo "oracle $o str $st\n";
 run($d,$q,[1=>PDO::PARAM_INT,2=>PDO::PARAM_INT,3=>PDO::PARAM_INT,4=>PDO::PARAM_BOOL]);
 run($d,$q,[1=>PDO::PARAM_LOB,2=>PDO::PARAM_LOB,3=>PDO::PARAM_LOB,4=>PDO::PARAM_STR]);
 run($d,$q,[1=>99,2=>99,3=>99,4=>PDO::PARAM_NULL]);
}
// bad column sets: duplicate var bound to two columns, name+index same col
$d->setAttribute(PDO::ATTR_ORACLE_NULLS,0); $d->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,false);
$s=$d->prepare("select 1 as a, 2 as b"); $s->bindColumn(1,$x); $s->bindColumn(2,$x); $s->execute(); $s->fetch(PDO::FETCH_BOUND); var_dump($x);
$s=$d->prepare("select 1 as a, 2 as b"); $s->bindColumn('a',$x); $s->bindColumn(1,$y); $s->execute(); $s->fetch(PDO::FETCH_BOUND); var_dump($x,$y);
$s=$d->prepare("select 1 as a, 2 as b"); $s->bindColumn(1,$x); $s->bindColumn(2,$y); $s->execute(); $s->fetch(PDO::FETCH_BOUND); $x=9; $s->closeCursor(); var_dump($x,$y);
// fetch after a bindColumn on different statement/object modes
$s=$d->prepare("select 1 as a, 2 as b"); $s->bindColumn(1,$x); $s->execute(); print_r($s->fetchObject()); var_dump($x);
$s=$d->prepare("select 1 as a, 2 as b"); $s->bindColumn(1,$x,PDO::PARAM_INT); $s->execute(); var_dump($s->fetch(PDO::FETCH_LAZY)->a); var_dump($x);
$s=$d->prepare("select 1 as a, 2 as b"); $s->bindColumn(1,$x,PDO::PARAM_STR); $s->execute(); var_dump($s->fetchAll(PDO::FETCH_ASSOC)); var_dump($x);
$s=$d->prepare("select 1 as a, 2 as b"); $s->bindColumn(1,$x,PDO::PARAM_STR); $s->execute(); var_dump($s->fetchAll(PDO::FETCH_COLUMN)); var_dump($x);
$s=$d->prepare("select 1 as a, 2 as b"); $s->bindColumn(1,$x,PDO::PARAM_STR); $s->execute(); var_dump($s->fetchAll(PDO::FETCH_KEY_PAIR)); var_dump($x);
$s=$d->prepare("select 1 as a, 2 as b"); $s->bindColumn(1,$x,PDO::PARAM_STR); $s->execute(); var_dump($s->fetchAll(PDO::FETCH_FUNC, fn($a,$b)=>$a+$b)); var_dump($x);
$s=$d->prepare("select 1 as a, 2 as b"); $s->bindColumn(1,$x,PDO::PARAM_STR); $s->execute(); var_dump($s->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC)); var_dump($x);
