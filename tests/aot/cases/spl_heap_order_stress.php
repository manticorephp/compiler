<?php
class RevPQ extends SplPriorityQueue { public function compare(mixed $a, mixed $b): int { return $b <=> $a; } }
class LenMax extends SplMaxHeap { protected function compare(mixed $a, mixed $b): int { return strlen($a) <=> strlen($b); } }
class MyMin extends SplMinHeap {}
$seed = 12345;
function rnd(int $m): int { global $seed; $seed = ($seed * 1103515245 + 12345) & 0x7fffffff; return $seed % $m; }
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 63, 64, 65, 1000] as $n) {
    $pq = new SplPriorityQueue; $rq = new RevPQ; $min = new SplMinHeap; $max = new SplMaxHeap; $lm = new LenMax; $mm = new MyMin;
    for ($i = 0; $i < $n; $i++) {
        $p = rnd(10);
        $pq->insert("d$i", $p); $rq->insert("d$i", $p); $min->insert($p); $max->insert($p); $lm->insert(str_repeat('x', $p) . $i); $mm->insert([$p, $i]);
    }
    $a = []; $b = []; $c = []; $d = []; $e = []; $f = [];
    while (!$pq->isEmpty()) { $a[] = $pq->extract(); }
    while ($rq->valid()) { $b[] = $rq->current(); $rq->next(); }
    while (!$min->isEmpty()) { $c[] = $min->extract(); }
    foreach ($max as $v) { $d[] = $v; }
    while (!$lm->isEmpty()) { $e[] = $lm->extract(); }
    while (!$mm->isEmpty()) { $f[] = implode(',', $mm->extract()); }
    echo $n, ' ', md5(implode('|', $a)), ' ', md5(implode('|', $b)), ' ', md5(implode(',', $c)), ' ', md5(implode(',', $d)), ' ', md5(implode(',', $e)), ' ', md5(implode(';', $f)), "\n";
}
$q = new SplPriorityQueue; $q->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
foreach ([['a', 2], ['b', 2], ['c', 5], ['d', 1], ['e', 5], ['f', [1, 2]], ['g', 2.5], ['h', "z"]] as [$d, $p]) { $q->insert($d, $p); }
echo $q->count(), json_encode($q->top()), "\n";
while ($q->valid()) { echo json_encode($q->extract()), ' '; }
echo "\n";
$q->setExtractFlags(SplPriorityQueue::EXTR_PRIORITY); $q->insert('x', 3); $q->insert('y', 4); echo $q->extract(), $q->top(), "\n";
$s = serialize($q); $u = unserialize($s); echo $u->count(), $u->extract(), "\n";
$h = new SplMinHeap; foreach ([5, 3, 8, 1, 9, 2] as $v) { $h->insert($v); } echo $h->top(), $h->count(), implode(',', iterator_to_array($h, false)), $h->count(), "\n";
class Boom extends SplMinHeap { protected function compare(mixed $a, mixed $b): int { if ($a === 4 || $b === 4) throw new Exception('boom'); return parent::compare($a, $b); } }
$bm = new Boom; $bm->insert(1); $bm->insert(2);
try { $bm->insert(4); } catch (Exception $e) { echo $e->getMessage(), ' '; }
try { $bm->top(); } catch (RuntimeException $e) { echo get_class($e), ' ', $bm->isCorrupted() ? 'corrupt' : 'ok', "\n"; }
