<?php
$e=0;$m='';$e2=0;$m2='';
$port=0;$srv=false;
for($p=53100;$p<53190;$p++){ $s=@stream_socket_server('udp://127.0.0.1:'.$p,$e,$m,STREAM_SERVER_BIND); if($s!==false){$srv=$s;$port=$p;break;} }
if($srv===false){ echo "BINDFAIL\n"; }
else {
  var_dump(stream_socket_get_name($srv, false) === '127.0.0.1:'.$port);
  $cli=stream_socket_client('udp://127.0.0.1:'.$port,$e2,$m2);
  stream_socket_sendto($cli, "PING");
  $addr='';
  $data = stream_socket_recvfrom($srv, 64, 0, $addr);
  var_dump($data);
  var_dump(strpos($addr, '127.0.0.1:') === 0);
  stream_socket_sendto($srv, "PONG", 0, $addr);   // reply to sender addr
  $back = fread($cli, 64);
  var_dump($back);
  fclose($cli); fclose($srv);
}
