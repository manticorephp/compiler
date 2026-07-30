<?php
// An array property appended BOTH an object and an array keeps one runtime
// repr nibble, so the last store's kind overwrote the earlier one and readers
// mis-read every element of the other kind: `$r instanceof Sep` loaded a class
// id out of a row array's LENGTH word. Conflicting element stores mean the
// slot holds cells. symfony's Table::addRow appends a TableSeparator and an
// array_values() array to the same $this->rows.
// `instanceof` over an ERASED operand also has to classify the carrier at
// runtime rather than inttoptr whatever it finds.
class Sep {}
class T {
    private array $rows = [];
    private array $headers = [];
    public function setHeaders(array $h): static { $this->headers = \array_values($h); return $this; }
    public function addRow(Sep|array $row): static
    {
        if ($row instanceof Sep) { $this->rows[] = $row; return $this; }
        $this->rows[] = \array_values($row);
        return $this;
    }
    public function addRows(array $rows): static
    {
        foreach ($rows as $r) { $this->addRow($r); }
        return $this;
    }
    public function dump(): void
    {
        echo 'rows=', \count($this->rows), " headers=", \count($this->headers), "\n";
        foreach ($this->rows as $i => $r) {
            if ($r instanceof Sep) { echo '  [', $i, "] SEP\n"; continue; }
            echo '  [', $i, '] ', \implode('|', $r), "\n";
        }
    }
}
$t = new T();
$t->setHeaders(['name', 'qty', 'category']);
$t->addRows([
    ['hammer', 12, 'tools'],
    ['nails', 480, 'tools'],
    new Sep(),
    ['ledger', 3, 'office'],
]);
$t->dump();
