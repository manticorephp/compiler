<?php
interface VoterI {}
interface CacheableVoterI extends VoterI {}
class AuthVoter implements CacheableVoterI {}
class Plain {}
function chk(mixed $c) { var_dump(is_a($c, VoterI::class, true), is_a($c, CacheableVoterI::class, true), is_subclass_of($c, VoterI::class)); }
chk(json_decode('"AuthVoter"')); chk('AuthVoter'); chk(json_decode('"Plain"')); chk(new AuthVoter);
function viaParam($def) { $cls = $def['c']; return is_a($cls, VoterI::class, true); }
var_dump(viaParam(json_decode('{"c":"AuthVoter"}', true)));
