<?php

class ESDQuiet
{
    public int $n;

    public function __construct(int $n)
    {
        $this->n = $n;
    }
}

class ESD
{
    public int $n;

    public function __construct(int $n)
    {
        $this->n = $n;
    }

    public function __destruct()
    {
        echo 'dtor ', $this->n, "\n";
    }
}

function esd_overwrite(): void
{
    echo "[overwrite]\n";
    $a = [];
    $a['x'] = new ESD(1);
    $a['x'] = new ESD(2);
    echo 'live ', $a['x']->n, "\n";
    unset($a['x']);
    echo "unset done\n";
}

function esd_vec(): void
{
    echo "[vec]\n";
    $v = [];
    $v[] = new ESD(11);
    $v[0] = new ESD(12);
    echo 'live ', $v[0]->n, "\n";
    unset($v[0]);
    echo "unset done\n";
}

function esd_copy_keeps_alias(): void
{
    echo "[copy]\n";
    $a = ['x' => new ESDQuiet(21)];
    $b = $a;
    $a['x'] = new ESDQuiet(22);
    echo 'b ', $b['x']->n, ' a ', $a['x']->n, "\n";
    echo 'b again ', $b['x']->n, "\n";
}

function esd_unset_keeps_alias(): void
{
    echo "[unset-alias]\n";
    $a = ['x' => new ESDQuiet(31)];
    $b = $a;
    unset($a['x']);
    echo 'b ', $b['x']->n, "\n";
    echo 'a count ', \count($a), "\n";
}

function esd_read_outlives_slot(): void
{
    echo "[read-outlives]\n";
    $a = ['x' => new ESD(41)];
    $keep = $a['x'];
    $a['x'] = new ESD(42);
    echo 'keep ', $keep->n, "\n";
    unset($a['x']);
    echo 'keep ', $keep->n, "\n";
}

function esd_strings(): void
{
    echo "[strings]\n";
    $m = [];
    $m['k'] = 'a' . 'lpha';
    $m['k'] = 'b' . 'eta';
    echo $m['k'], "\n";
    unset($m['k']);
    echo 'count ', \count($m), "\n";
}

esd_overwrite();
esd_vec();
esd_copy_keeps_alias();
esd_unset_keeps_alias();
esd_read_outlives_slot();
esd_strings();
echo "end\n";
