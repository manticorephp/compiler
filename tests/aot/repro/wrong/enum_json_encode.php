<?php
// json_encode of a backed enum prints {"name","value"} instead of the backing value
// issue: #39
enum E: string { case A = 'a'; case B = 'b'; }
echo json_encode(E::A), json_encode([E::B]), json_encode(['k' => E::A]), "\n";
