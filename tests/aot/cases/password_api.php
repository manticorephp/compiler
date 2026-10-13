<?php
$known = '$2y$10$Q6PSkpYd5QzUqv0tgmX7oOrvB1bLGO5F.JCLwwFyVMIyZeDFn1j8e';
var_dump(password_verify('rasmuslerdorf', '$2y$07$BCryptRequires22Chrcte/VlQH0piJtjXl.0t1XkA8pw9dMXTpOq'));
var_dump(password_verify('wrong', '$2y$07$BCryptRequires22Chrcte/VlQH0piJtjXl.0t1XkA8pw9dMXTpOq'));
var_dump(password_verify('x', ''), password_verify('x', 'plain'));
var_dump(password_verify('pw', crypt('pw', '$6$abcdefgh$')));
var_dump(password_verify('pw', crypt('pw', '$1$abcdefgh$')));
$h = password_hash('secret', PASSWORD_DEFAULT, ['cost' => 5]);
var_dump(strlen($h), substr($h, 0, 7), password_verify('secret', $h), password_verify('Secret', $h));
var_dump(password_get_info($h));
var_dump(password_get_info('not a hash'));
var_dump(password_needs_rehash($h, PASSWORD_BCRYPT, ['cost' => 5]), password_needs_rehash($h, PASSWORD_BCRYPT, ['cost' => 6]));
var_dump(password_needs_rehash($h, PASSWORD_DEFAULT), password_needs_rehash('x', PASSWORD_BCRYPT));
var_dump(PASSWORD_DEFAULT, PASSWORD_BCRYPT, PASSWORD_BCRYPT_DEFAULT_COST);
var_dump(password_hash('a', null, ['cost' => 4]) !== password_hash('a', null, ['cost' => 4]));
foreach ([3, 32] as $c) {
    try { password_hash('a', PASSWORD_BCRYPT, ['cost' => $c]); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
}
try { password_hash("a\0b", PASSWORD_BCRYPT); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
try { password_hash("a", 'nope'); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
