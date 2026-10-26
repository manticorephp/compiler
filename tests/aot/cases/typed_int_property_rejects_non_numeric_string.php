<?php
class Tp { public int $x = 0; }
$o = new Tp();
try { $o->x = 'abc'; echo "no error\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$k = 'x';
try { $o->$k = 'abc'; echo "no error\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { $o->x .= 'abc'; echo "no error\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$o->x = '12'; var_dump($o->x);
$set = \Closure::bind(function (string $k, string $v): void { $this->$k = $v; }, $o, 'Tp');
try { $set('x', 'abc'); echo "no error\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$set('x', '7'); var_dump($o->x);
