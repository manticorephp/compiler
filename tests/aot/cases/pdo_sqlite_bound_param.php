<?php
$d = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function t($l,$f){ echo "-- $l\n"; try{ $f(); }catch(Throwable $e){ echo get_class($e),': ',$e->getMessage(),"\n"; } }
t('mutate',function() use($d){ $v=1; $s=$d->prepare('select ? as a'); $s->bindParam(1,$v); $v=2; $s->execute(); var_dump($s->fetch(PDO::FETCH_ASSOC)); $v=3; $s->execute(); var_dump($s->fetch(PDO::FETCH_ASSOC)); });
t('named+int',function() use($d){ $v='12'; $w=null; $s=$d->prepare('select :a as a, :b as b'); $s->bindParam(':a',$v,PDO::PARAM_INT); $s->bindParam('b',$w,PDO::PARAM_INT); $v='13'; $w=4; $s->execute(); var_dump($s->fetch(PDO::FETCH_ASSOC)); });
t('null int',function() use($d){ $w=null; $s=$d->prepare('select ? as a'); $s->bindParam(1,$w,PDO::PARAM_INT); $s->execute(); var_dump($s->fetch(PDO::FETCH_ASSOC)); $w=null; $w=5; $s->execute(); var_dump($s->fetch(PDO::FETCH_ASSOC));});
t('bindValue after',function() use($d){ $v=1; $s=$d->prepare('select ? as a'); $s->bindParam(1,$v); $s->bindValue(1,7); $v=2; $s->execute(); var_dump($s->fetch(PDO::FETCH_ASSOC));});
t('bindParam after bindValue',function() use($d){ $v=1; $s=$d->prepare('select ? as a'); $s->bindValue(1,7); $s->bindParam(1,$v);  $v=2; $s->execute(); var_dump($s->fetch(PDO::FETCH_ASSOC));});
t('execute arr clears',function() use($d){ $v=1; $s=$d->prepare('select ? as a'); $s->bindParam(1,$v);  $v=2; $s->execute([9]); var_dump($s->fetch(PDO::FETCH_ASSOC)); $s->execute(); var_dump($s->fetch(PDO::FETCH_ASSOC));});
t('loop',function() use($d){ $d->exec('create table k(a int, b text)'); $s=$d->prepare('insert into k values(:a,:b)'); $s->bindParam(':a',$a,PDO::PARAM_INT); $s->bindParam(':b',$b); foreach([[1,'x'],[2,'y']] as [$a,$b]) $s->execute(); var_dump($d->query('select * from k')->fetchAll(PDO::FETCH_NUM)); });
t('inout',function() use($d){ $v='abc'; $s=$d->prepare('select ? as a'); var_dump($s->bindParam(1,$v,PDO::PARAM_STR|PDO::PARAM_INPUT_OUTPUT,10)); $s->execute(); var_dump($s->fetch(PDO::FETCH_ASSOC)); var_dump($v);});
t('maxlen',function() use($d){ $v='abcdef'; $s=$d->prepare('select ? as a'); var_dump($s->bindParam(1,$v,PDO::PARAM_STR,3,null)); $s->execute(); var_dump($s->fetch(PDO::FETCH_ASSOC));});
t('bad idx',function() use($d){ $v=1; $s=$d->prepare('select ? as a'); var_dump($s->bindParam(2,$v)); var_dump($s->bindParam(0,$v)); });
t('bool',function() use($d){ $v=true; $s=$d->prepare('select ? as a'); $s->bindParam(1,$v,PDO::PARAM_BOOL); $v=false; $s->execute(); var_dump($s->fetch(PDO::FETCH_ASSOC));});
t('obj',function() use($d){ $v=1; $s=$d->prepare('select ? as a'); $s->bindParam(1,$v); $s->bindParam(1,$v);$w=2; $s->bindParam(1,$w); $v=9; $s->execute(); var_dump($s->fetch(PDO::FETCH_ASSOC));});
