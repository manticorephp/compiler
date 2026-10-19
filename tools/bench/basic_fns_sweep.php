<?php
// scaling sweep: each case run at n and 4n; prints name t1_us t4_us
$S = str_repeat("The quick brown fox, jumps over the lazy dog.\n", 1);
function mk_str(int $n): string { return substr(str_repeat("The quick brown fox, jumps over the lazy dog.\n", intdiv($n, 40) + 1), 0, $n); }
/** @return int[] */
function mk_ints(int $n): array { $a = []; for ($i = 0; $i < $n; $i++) { $a[] = ($i * 2654435761) % 1000003; } return $a; }
/** @return string[] */
function mk_strs(int $n): array { $a = []; for ($i = 0; $i < $n; $i++) { $a[] = "k" . (($i * 2654435761) % 1000003); } return $a; }
/** @return array<string,int> */
function mk_assoc(int $n): array { $a = []; for ($i = 0; $i < $n; $i++) { $a["k" . $i] = $i; } return $a; }
$cases = [
 'str_concat_loop' => function (int $n) { $s = ''; for ($i = 0; $i < $n; $i++) { $s .= 'ab'; } return strlen($s); },
 'str_prepend_loop' => function (int $n) { $s = ''; for ($i = 0; $i < intdiv($n, 8); $i++) { $s = 'ab' . $s; } return strlen($s); },
 'array_append' => function (int $n) { $a = []; for ($i = 0; $i < $n; $i++) { $a[] = $i; } return count($a); },
 'array_assoc_build' => function (int $n) { return count(mk_assoc($n)); },
 'array_shift_loop' => function (int $n) { $a = mk_ints(intdiv($n, 4)); $s = 0; while ($a) { $s += array_shift($a); } return $s; },
 'array_pop_loop' => function (int $n) { $a = mk_ints($n); $s = 0; while ($a) { $s += array_pop($a); } return $s; },
 'array_unshift_loop' => function (int $n) { $a = []; for ($i = 0; $i < intdiv($n, 4); $i++) { array_unshift($a, $i); } return count($a); },
 'array_merge_loop' => function (int $n) { $a = []; for ($i = 0; $i < intdiv($n, 16); $i++) { $a = array_merge($a, [$i]); } return count($a); },
 'array_merge_big' => function (int $n) { $x = mk_ints($n); return count(array_merge($x, $x)); },
 'array_slice_loop' => function (int $n) { $a = mk_ints($n); $s = 0; for ($i = 0; $i < $n; $i += 50) { $s += count(array_slice($a, $i, 10)); } return $s; },
 'array_splice' => function (int $n) { $a = mk_ints($n); for ($i = 0; $i < 100; $i++) { array_splice($a, 5, 1); } return count($a); },
 'in_array_miss' => function (int $n) { $a = mk_strs(2500); $s = 0; for ($i = 0; $i < intdiv($n, 100); $i++) { if (in_array("zz", $a)) { $s++; } } return $s; },
 'array_search_int' => function (int $n) { $a = mk_ints(2500); $s = 0; for ($i = 0; $i < intdiv($n, 100); $i++) { $s += (int)array_search(-1, $a); } return $s; },
 'array_key_exists' => function (int $n) { $a = mk_assoc(1000); $s = 0; for ($i = 0; $i < $n; $i++) { if (array_key_exists("k" . ($i % 2000), $a)) { $s++; } } return $s; },
 'isset_loop' => function (int $n) { $a = mk_assoc(1000); $s = 0; for ($i = 0; $i < $n; $i++) { if (isset($a["k" . ($i % 2000)])) { $s++; } } return $s; },
 'array_unique' => function (int $n) { return count(array_unique(mk_ints($n))); },
 'array_unique_str' => function (int $n) { return count(array_unique(mk_strs($n))); },
 'array_flip' => function (int $n) { return count(array_flip(mk_strs($n))); },
 'array_count_values' => function (int $n) { return count(array_count_values(mk_strs($n))); },
 'array_diff' => function (int $n) { return count(array_diff(mk_ints($n), mk_ints(intdiv($n, 2)))); },
 'array_intersect' => function (int $n) { return count(array_intersect(mk_ints($n), mk_ints(intdiv($n, 2)))); },
 'array_diff_key' => function (int $n) { return count(array_diff_key(mk_assoc($n), mk_assoc(intdiv($n, 2)))); },
 'array_intersect_key' => function (int $n) { return count(array_intersect_key(mk_assoc($n), mk_assoc(intdiv($n, 2)))); },
 'array_reverse' => function (int $n) { return count(array_reverse(mk_ints($n))); },
 'array_combine' => function (int $n) { return count(array_combine(mk_strs($n), mk_ints($n))); },
 'array_column' => function (int $n) { $r = []; for ($i = 0; $i < intdiv($n, 4); $i++) { $r[] = ['id' => $i, 'v' => $i]; } return count(array_column($r, 'v', 'id')); },
 'array_chunk' => function (int $n) { return count(array_chunk(mk_ints($n), 7)); },
 'array_fill' => function (int $n) { return count(array_fill(0, $n, 1)); },
 'array_pad' => function (int $n) { return count(array_pad([], $n, 1)); },
 'array_map' => function (int $n) { return count(array_map(fn($x) => $x + 1, mk_ints($n))); },
 'array_filter' => function (int $n) { return count(array_filter(mk_ints($n), fn($x) => $x & 1)); },
 'array_reduce' => function (int $n) { return array_reduce(mk_ints($n), fn($c, $x) => $c + $x, 0); },
 'array_walk' => function (int $n) { $a = mk_ints($n); array_walk($a, function (&$v, $k) { $v++; }); return count($a); },
 'array_sum_max' => function (int $n) { $a = mk_ints($n); return array_sum($a) + max($a) + min($a); },
 'array_keys_values' => function (int $n) { $a = mk_assoc($n); return count(array_keys($a)) + count(array_values($a)); },
 'array_rand_shuffle' => function (int $n) { $a = mk_ints($n); shuffle($a); return count($a); },
 'range' => function (int $n) { return count(range(1, $n)); },
 'sort_int' => function (int $n) { $a = mk_ints($n); sort($a); return $a[0]; },
 'sort_str' => function (int $n) { $a = mk_strs($n); sort($a); return strlen($a[0]); },
 'rsort_int' => function (int $n) { $a = mk_ints($n); rsort($a); return $a[0]; },
 'usort' => function (int $n) { $a = mk_ints($n); usort($a, fn($x, $y) => $x <=> $y); return $a[0]; },
 'ksort' => function (int $n) { $a = mk_assoc($n); krsort($a); ksort($a); return count($a); },
 'asort' => function (int $n) { $a = mk_assoc($n); arsort($a); asort($a); return count($a); },
 'sort_sorted' => function (int $n) { $a = range(1, $n); sort($a); return $a[0]; },
 'sort_reverse_sorted' => function (int $n) { $a = range($n, 1); sort($a); return $a[0]; },
 'sort_equal' => function (int $n) { $a = array_fill(0, $n, 5); sort($a); return $a[0]; },
 'array_multisort' => function (int $n) { $a = mk_ints($n); $b = mk_ints($n); array_multisort($a, $b); return $a[0]; },
 'implode' => function (int $n) { return strlen(implode(',', mk_ints($n))); },
 'explode' => function (int $n) { return count(explode(',', implode(',', mk_ints($n)))); },
 'explode_limit' => function (int $n) { $s = implode(',', mk_ints($n)); $c = 0; for ($i = 0; $i < 20; $i++) { $c += count(explode(',', $s, 3)); } return $c; },
 'str_split' => function (int $n) { return count(str_split(mk_str($n))); },
 'str_replace' => function (int $n) { return strlen(str_replace('fox', 'cat', mk_str($n))); },
 'str_replace_arr' => function (int $n) { return strlen(str_replace(['fox', 'dog', 'the'], ['c', 'd', 'e'], mk_str($n))); },
 'str_ireplace' => function (int $n) { return strlen(str_ireplace('FOX', 'cat', mk_str($n))); },
 'strtr_arr' => function (int $n) { return strlen(strtr(mk_str($n), ['fox' => 'cat', 'dog' => 'x', 'quick' => 'slow'])); },
 'strtr_chars' => function (int $n) { return strlen(strtr(mk_str($n), 'abc', 'xyz')); },
 'substr_count' => function (int $n) { return substr_count(mk_str($n), 'o'); },
 'substr_replace' => function (int $n) { $s = mk_str($n); for ($i = 0; $i < 100; $i++) { $s = substr_replace($s, 'x', 5, 1); } return strlen($s); },
 'strpos_loop' => function (int $n) { $s = mk_str($n); $p = 0; $c = 0; while (($p = strpos($s, 'fox', $p)) !== false) { $p++; $c++; } return $c; },
 'strrpos' => function (int $n) { return strrpos(mk_str($n), 'The'); },
 'stripos_loop' => function (int $n) { $s = mk_str($n); $p = 0; $c = 0; while (($p = stripos($s, 'FOX', $p)) !== false) { $p++; $c++; } return $c; },
 'str_contains_miss' => function (int $n) { return (int)str_contains(mk_str($n), 'zzz'); },
 'substr_loop' => function (int $n) { $s = mk_str($n); $c = 0; for ($i = 0; $i < $n; $i++) { $c += strlen(substr($s, $i, 3)); } return $c; },
 'str_index_loop' => function (int $n) { $s = mk_str($n); $c = 0; for ($i = 0; $i < $n; $i++) { $c += ord($s[$i]); } return $c; },
 'str_index_write' => function (int $n) { $s = mk_str($n); for ($i = 0; $i < $n; $i++) { $s[$i] = 'x'; } return strlen($s); },
 'strlen_loop' => function (int $n) { $s = mk_str($n); $c = 0; for ($i = 0; $i < strlen($s); $i++) { $c++; } return $c; },
 'trim_ltrim' => function (int $n) { $s = str_repeat(' ', $n) . 'x' . str_repeat(' ', $n); return strlen(trim($s)) + strlen(ltrim($s)) + strlen(rtrim($s)); },
 'ucwords' => function (int $n) { return strlen(ucwords(mk_str($n))); },
 'strtoupper' => function (int $n) { return strlen(strtoupper(mk_str($n)) . strtolower(mk_str($n))); },
 'strrev' => function (int $n) { return strlen(strrev(mk_str($n))); },
 'str_pad' => function (int $n) { return strlen(str_pad('x', $n, 'ab')); },
 'str_repeat' => function (int $n) { return strlen(str_repeat('ab', $n)); },
 'wordwrap' => function (int $n) { return strlen(wordwrap(mk_str($n), 40, "\n", true)); },
 'nl2br' => function (int $n) { return strlen(nl2br(mk_str($n))); },
 'addslashes' => function (int $n) { return strlen(addslashes(mk_str($n))); },
 'htmlspecialchars' => function (int $n) { return strlen(htmlspecialchars(mk_str($n))); },
 'html_entity_decode' => function (int $n) { return strlen(html_entity_decode(str_repeat('&amp;&lt;x', intdiv($n, 8)))); },
 'strip_tags' => function (int $n) { return strlen(strip_tags(str_repeat('<b>x</b> y ', intdiv($n, 8)))); },
 'urlencode' => function (int $n) { return strlen(urlencode(mk_str($n)) . rawurldecode(urlencode(mk_str($n)))); },
 'base64' => function (int $n) { return strlen(base64_decode(base64_encode(mk_str($n)))); },
 'bin2hex' => function (int $n) { return strlen(hex2bin(bin2hex(mk_str($n)))); },
 'md5_sha1_crc' => function (int $n) { $s = mk_str($n); return strlen(md5($s) . sha1($s)) + crc32($s); },
 'hash_sha256' => function (int $n) { return strlen(hash('sha256', mk_str($n))); },
 'sprintf_loop' => function (int $n) { $c = 0; for ($i = 0; $i < $n; $i++) { $c += strlen(sprintf('%05d:%s:%.2f', $i, 'x', $i / 3)); } return $c; },
 'number_format' => function (int $n) { $c = 0; for ($i = 0; $i < $n; $i++) { $c += strlen(number_format($i * 1.5, 2)); } return $c; },
 'intval_strval' => function (int $n) { $c = 0; for ($i = 0; $i < $n; $i++) { $c += intval((string)$i) + strlen(strval($i)); } return $c; },
 'str_word_count' => function (int $n) { return str_word_count(mk_str($n)); },
 'ucfirst_lcfirst' => function (int $n) { $c = 0; for ($i = 0; $i < $n; $i++) { $c += strlen(ucfirst('abc')); } return $c; },
 'similar_text' => function (int $n) { return similar_text(mk_str(min($n, 3000)), mk_str(min($n, 3000) + 7)); },
 'levenshtein' => function (int $n) { return levenshtein(substr(mk_str($n), 0, 250), substr(mk_str($n + 9), 3, 250)); },
 'preg_match_loop' => function (int $n) { $s = mk_str($n); $c = 0; $o = 0; while (preg_match('/fox/', $s, $m, PREG_OFFSET_CAPTURE, $o)) { $o = $m[0][1] + 1; $c++; } return $c; },
 'preg_match_all' => function (int $n) { return preg_match_all('/\w+/', mk_str($n), $m); },
 'preg_replace' => function (int $n) { return strlen(preg_replace('/o/', '0', mk_str($n))); },
 'preg_replace_cb' => function (int $n) { return strlen(preg_replace_callback('/\w+/', fn($m) => strtoupper($m[0]), mk_str($n))); },
 'preg_split' => function (int $n) { return count(preg_split('/\s+/', mk_str($n))); },
 'preg_quote' => function (int $n) { return strlen(preg_quote(mk_str($n))); },
 'mb_strlen_substr' => function (int $n) { $s = mk_str($n); return mb_strlen($s) + strlen(mb_substr($s, 5, 100)) + strlen(mb_strtoupper($s)); },
 'mb_substr_loop' => function (int $n) { $s = mk_str($n); $c = 0; for ($i = 0; $i < intdiv($n, 8); $i++) { $c += strlen(mb_substr($s, $i, 1)); } return $c; },
 'mb_str_split' => function (int $n) { return count(mb_str_split(mk_str($n))); },
 'json_encode' => function (int $n) { return strlen(json_encode(mk_assoc($n))); },
 'json_encode_list' => function (int $n) { return strlen(json_encode(mk_ints($n))); },
 'json_decode' => function (int $n) { return count(json_decode(json_encode(mk_assoc($n)), true)); },
 'serialize' => function (int $n) { return count(unserialize(serialize(mk_assoc($n)))); },
 'var_export' => function (int $n) { return strlen(var_export(mk_ints($n), true)); },
 'print_r' => function (int $n) { return strlen(print_r(mk_assoc($n), true)); },
 'http_build_query' => function (int $n) { return strlen(http_build_query(mk_assoc($n))); },
 'parse_str' => function (int $n) { parse_str(http_build_query(mk_assoc($n)), $o); return count($o); },
 'str_getcsv' => function (int $n) { return count(str_getcsv(implode(',', mk_ints($n)))); },
 'compact_extract' => function (int $n) { $c = 0; for ($i = 0; $i < intdiv($n, 4); $i++) { $a = 1; $b = 2; $r = compact('a', 'b'); $c += count($r); } return $c; },
 'array_key_first_last' => function (int $n) { $a = mk_ints($n); $c = 0; for ($i = 0; $i < $n; $i++) { $c += array_key_last($a) + array_key_first($a); } return $c; },
 'count_loop' => function (int $n) { $a = mk_ints($n); $c = 0; for ($i = 0; $i < count($a); $i++) { $c++; } return $c; },
 'foreach_copy_write' => function (int $n) { $a = mk_ints($n); foreach ($a as $k => $v) { $a[$k] = $v + 1; } return count($a); },
 'array_copy_modify_loop' => function (int $n) { $a = mk_ints(intdiv($n, 8)); $c = 0; for ($i = 0; $i < 200; $i++) { $b = $a; $b[0] = $i; $c += $b[0]; } return $c; },
 'unset_loop' => function (int $n) { $a = mk_assoc($n); for ($i = 0; $i < $n; $i++) { unset($a["k" . $i]); } return count($a); },
 'array_key_unset_front' => function (int $n) { $a = mk_ints($n); for ($i = 0; $i < $n; $i++) { unset($a[$i]); } return count($a); },
 'list_each_array_is_list' => function (int $n) { $a = mk_ints($n); return (int)array_is_list($a); },
 'array_walk_recursive' => function (int $n) { $a = [mk_ints(intdiv($n, 2)), mk_ints(intdiv($n, 2))]; $c = 0; array_walk_recursive($a, function ($v) use (&$c) { $c++; }); return $c; },
 'array_merge_recursive' => function (int $n) { return count(array_merge_recursive(mk_assoc(intdiv($n, 2)), mk_assoc(intdiv($n, 2)))); },
 'array_replace' => function (int $n) { return count(array_replace(mk_assoc($n), mk_assoc(intdiv($n, 2)))); },
 'spread_merge' => function (int $n) { $x = mk_ints($n); return count([...$x, ...$x]); },
 'SplStack' => function (int $n) { $s = new SplStack(); for ($i = 0; $i < $n; $i++) { $s->push($i); } $c = 0; while (!$s->isEmpty()) { $c += $s->pop(); } return $c; },
 'SplQueue' => function (int $n) { $s = new SplQueue(); for ($i = 0; $i < $n; $i++) { $s->enqueue($i); } $c = 0; while (!$s->isEmpty()) { $c += $s->dequeue(); } return $c; },
 'SplPQ' => function (int $n) { $s = new SplPriorityQueue(); for ($i = 0; $i < intdiv($n, 2); $i++) { $s->insert($i, $i * 7 % 1000); } $c = 0; while (!$s->isEmpty()) { $c += $s->extract(); } return $c; },
 'SplMinHeap' => function (int $n) { $s = new SplMinHeap(); for ($i = 0; $i < intdiv($n, 2); $i++) { $s->insert($i * 7 % 1000); } $c = 0; while (!$s->isEmpty()) { $c += $s->extract(); } return $c; },
 'SplObjectStorage' => function (int $n) { $s = new SplObjectStorage(); $o = []; for ($i = 0; $i < intdiv($n, 4); $i++) { $x = new stdClass(); $o[] = $x; $s->attach($x); } $c = 0; foreach ($o as $x) { $c += (int)$s->contains($x); } return $c; },
 'SplFixedArray' => function (int $n) { $s = new SplFixedArray($n); for ($i = 0; $i < $n; $i++) { $s[$i] = $i; } $c = 0; for ($i = 0; $i < $n; $i++) { $c += $s[$i]; } return $c; },
 'ArrayObject' => function (int $n) { $s = new ArrayObject(); for ($i = 0; $i < $n; $i++) { $s[] = $i; } $c = 0; foreach ($s as $v) { $c += $v; } return $c; },
 'generator' => function (int $n) { $g = (function () use ($n) { for ($i = 0; $i < $n; $i++) { yield $i; } })(); $c = 0; foreach ($g as $v) { $c += $v; } return $c; },
 'iterator_to_array' => function (int $n) { $g = (function () use ($n) { for ($i = 0; $i < $n; $i++) { yield $i; } })(); return count(iterator_to_array($g)); },
 'obj_new_loop' => function (int $n) { $c = 0; for ($i = 0; $i < $n; $i++) { $o = new stdClass(); $o->a = $i; $c += $o->a; } return $c; },
 'obj_dynprop_many' => function (int $n) { $o = new stdClass(); for ($i = 0; $i < intdiv($n, 4); $i++) { $o->{"p$i"} = $i; } return count((array)$o); },
 'call_user_func' => function (int $n) { $c = 0; for ($i = 0; $i < $n; $i++) { $c += call_user_func('abs', -$i); } return $c; },
 'func_str_dispatch' => function (int $n) { $f = 'strlen'; $c = 0; for ($i = 0; $i < $n; $i++) { $c += $f('abc'); } return $c; },
 'static_prop_arr' => function (int $n) { $c = 0; for ($i = 0; $i < $n; $i++) { SW::$a[] = $i; } return count(SW::$a); },
 'prop_arr_append' => function (int $n) { $o = new SW(); for ($i = 0; $i < $n; $i++) { $o->b[] = $i; } return count($o->b); },
 'prop_arr_assoc_set' => function (int $n) { $o = new SW(); for ($i = 0; $i < $n; $i++) { $o->c["k$i"] = $i; } return count($o->c); },
 'arr_passed_by_val_append' => function (int $n) { $a = []; for ($i = 0; $i < intdiv($n, 4); $i++) { $a = sw_push($a, $i); } return count($a); },
 'arr_by_ref_append' => function (int $n) { $a = []; for ($i = 0; $i < $n; $i++) { sw_pushr($a, $i); } return count($a); },
 'str_by_ref_append' => function (int $n) { $a = ''; for ($i = 0; $i < $n; $i++) { sw_cat($a); } return strlen($a); },
 'nested_arr_write' => function (int $n) { $a = []; for ($i = 0; $i < $n; $i++) { $a[$i % 100][] = $i; } return count($a); },
 'nested_arr_read_modify' => function (int $n) { $a = ['x' => ['y' => []]]; for ($i = 0; $i < $n; $i++) { $a['x']['y'][] = $i; } return count($a['x']['y']); },
 'ob_loop' => function (int $n) { ob_start(); for ($i = 0; $i < $n; $i++) { echo 'ab'; } return strlen(ob_get_clean()); },
 'str_interp_loop' => function (int $n) { $s = ''; for ($i = 0; $i < $n; $i++) { $s .= "item $i,"; } return strlen($s); },
 'array_str_keys_ints' => function (int $n) { $a = []; for ($i = 0; $i < $n; $i++) { $a[(string)$i] = $i; } return count($a); },
 'ctype_loop' => function (int $n) { $c = 0; for ($i = 0; $i < $n; $i++) { $c += (int)ctype_digit('12345') + (int)is_numeric('1.5'); } return $c; },
 'version_compare' => function (int $n) { $c = 0; for ($i = 0; $i < intdiv($n, 4); $i++) { $c += version_compare('1.2.3', '1.10.0'); } return $c; },
 'date_loop' => function (int $n) { $c = 0; for ($i = 0; $i < intdiv($n, 8); $i++) { $c += strlen(date('Y-m-d H:i:s', $i * 3600)); } return $c; },
 'strtotime_loop' => function (int $n) { $c = 0; for ($i = 0; $i < intdiv($n, 8); $i++) { $c += strtotime('2024-01-15 10:00:00') > 0 ? 1 : 0; } return $c; },
 'uniqid_rand' => function (int $n) { $c = 0; for ($i = 0; $i < $n; $i++) { $c += mt_rand(1, 10) + rand(0, 3); } return $c; },
 'file_put_get' => function (int $n) { $f = sys_get_temp_dir() . '/sw_probe.txt'; file_put_contents($f, mk_str($n)); $r = strlen(file_get_contents($f)); $r += count(file($f)); unlink($f); return $r; },
 'fwrite_loop' => function (int $n) { $f = fopen('php://memory', 'w+'); for ($i = 0; $i < $n; $i++) { fwrite($f, "ab\n"); } rewind($f); $c = 0; while (($l = fgets($f)) !== false) { $c++; } return $c; },
];
class SW { /** @var int[] */ public static array $a = []; /** @var int[] */ public array $b = []; /** @var array<string,int> */ public array $c = []; }
/** @param int[] $a @return int[] */
function sw_push(array $a, int $i): array { $a[] = $i; return $a; }
/** @param int[] $a */
function sw_pushr(array &$a, int $i): void { $a[] = $i; }
function sw_cat(string &$s): void { $s .= 'x'; }

$base = (int)($argv[1] ?? 20000);
$only = $argv[2] ?? '';
foreach ($cases as $name => $fn) {
    if ($only !== '' && !str_contains($name, $only)) { continue; }
    $t = [];
    foreach ([$base, $base * 4] as $n) {
        $best = PHP_INT_MAX;
        for ($r = 0; $r < 3; $r++) {
            $t0 = hrtime(true);
            $fn($n);
            $d = hrtime(true) - $t0;
            if ($d < $best) { $best = $d; }
            if ($best > 400000000) { break; }
        }
        $t[] = intdiv($best, 1000);
    }
    echo $name, "\t", $t[0], "\t", $t[1], "\n";
}
