<?php
// `unset()` of a `static` local released at the LOAD's type: on the odd calls
// the load is typed by the decl (`string`) while the cell holds the object the
// even call before stored — a string release over an object header. The cell
// releases at the DECL's flavour under the store scan's verdict (vetoed here:
// the object store disagrees), never at the load's.
class O { public string $s = 'o'; }
function f(int $i): string
{
    static $v = '';
    if ($i % 2 === 0) {
        $v = new O;
        $v->s = 'o' . $i;
        return $v->s;
    }
    unset($v);
    return 'unset';
}
for ($i = 0; $i < 6; $i++) { echo f($i), "\n"; }
echo "done\n";
