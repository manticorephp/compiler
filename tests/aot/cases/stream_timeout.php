<?php
// A server accepts but sends nothing; a 1s read timeout must make fread return
// empty with meta_data timed_out=true, not hang. (External `time` confirms it
// returns at ~1s; the elapsed is not asserted here because it needs microtime.)
$e=0;$m='';$e2=0;$m2='';
$port=0;$srv=false;
for ($p=52400;$p<52480;$p++){ $s=@stream_socket_server('tcp://127.0.0.1:'.$p,$e,$m); if($s!==false){$srv=$s;$port=$p;break;} }
if ($srv===false){ echo "BINDFAIL\n"; }
else {
    $cli=stream_socket_client('tcp://127.0.0.1:'.$port,$e2,$m2);
    $conn=stream_socket_accept($srv,2);
    stream_set_timeout($cli,1);
    $data=fread($cli,100);
    $meta=stream_get_meta_data($cli);
    echo "len=".strlen($data)." timed_out=".($meta['timed_out']?'1':'0')."\n";
    fclose($conn);fclose($cli);
}
fclose($srv);
