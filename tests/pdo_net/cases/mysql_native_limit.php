<?php
// max_prepared_stmt_count (set to 5 by mysql_native_limit.pre, restored by the .post hook): error 1461 at prepare, statements stay usable
foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_EXCEPTION] as $mode) {
    echo "== errmode $mode\n";
    $db = new PDO((string) getenv('PDO_MY_DSN'), getenv('PDO_USER') ?: 'u', getenv('PDO_PASS') ?: 'pw', [PDO::ATTR_ERRMODE => $mode, PDO::ATTR_EMULATE_PREPARES => false]);
    $held = [];
    for ($i = 0; $i < 8; $i++) {
        try {
            $s = $db->prepare('select ' . $i);
            if ($s === false) { echo "prepare $i => false ", json_encode($db->errorInfo()), "\n"; continue; }
            $held[] = $s;
            echo "prepare $i => ok\n";
        } catch (PDOException $e) {
            echo "prepare $i => EXC ", $e->getMessage(), ' ', json_encode($e->errorInfo), "\n";
        }
    }
    foreach ($held as $i => $s) { $s->execute(); echo $s->fetchColumn(), ' '; }
    echo "\n";
    // releasing one frees a slot
    $held[0] = null;
    $s = $db->prepare('select 99');
    echo $s === false ? "still full\n" : "slot reused\n";
    // an emulated prepare needs no slot
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $e = $db->prepare('select 100');
    $e->execute();
    echo $e->fetchColumn(), "\n";
    $held = [];
    $s = null;
    $e = null;
}
