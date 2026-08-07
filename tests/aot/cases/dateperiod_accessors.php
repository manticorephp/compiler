<?php

// DatePeriod's four accessors. getEndDate()/getRecurrences() are mirrors: php
// answers NULL from whichever one the constructor did not receive, and
// symfony/var-dumper's DateCaster branches on exactly that.

$p = new DatePeriod(new DateTime('2026-01-01', new DateTimeZone('UTC')),
                    new DateInterval('P1DT2H'),
                    new DateTime('2026-01-05', new DateTimeZone('UTC')));

$i = $p->getDateInterval();
echo get_class($i), ' y=', $i->y, ' m=', $i->m, ' d=', $i->d,
     ' h=', $i->h, ' i=', $i->i, ' s=', $i->s, ' inv=', $i->invert, "\n";
echo 'fmt=', $i->format('%y-%m-%d %h:%i:%s'), "\n";
echo 'start=', $p->getStartDate()->format('Y-m-d H:i:s'), "\n";
$e = $p->getEndDate();
echo 'end=', $e === null ? 'NULL' : $e->format('Y-m-d H:i:s'), "\n";
var_dump($p->getRecurrences());

$q = new DatePeriod(new DateTime('2026-03-01', new DateTimeZone('UTC')),
                    new DateInterval('P1D'), 3);
var_dump($q->getEndDate());
var_dump($q->getRecurrences());
echo 'q start=', $q->getStartDate()->format('Y-m-d'), "\n";
echo 'q iv d=', $q->getDateInterval()->d, "\n";

// The period still iterates after the accessors have run.
$n = 0;
foreach ($p as $d) { $n = $n + 1; }
echo 'iterated=', $n, "\n";
