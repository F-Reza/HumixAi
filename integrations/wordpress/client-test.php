<?php
// Isolated contract tests using WordPress HTTP stubs; not a WordPress runtime test.
define('ABSPATH',__DIR__);define('HUMIXAI_URL','https://humix.example');define('HUMIXAI_TOKENS',[7=>'private-token']);
function get_current_user_id(){return $GLOBALS['uid']??7;}
function wp_json_encode($v){return json_encode($v);}
function wp_safe_remote_request($url,$args){$GLOBALS['request']=[$url,$args];return $GLOBALS['response']??['code'=>202,'body'=>'{"success":true,"data":{"id":"job"}}'];}
function is_wp_error($v){return false;}
function wp_remote_retrieve_response_code($v){return $v['code'];}
function wp_remote_retrieve_body($v){return $v['body'];}
require __DIR__.'/humixai/client.php';
function check($condition,$message){if(!$condition)throw new Exception($message);}
function rejects($fn){try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new Exception('Expected rejection');}
foreach(['http://humix.example','https://user:pass@humix.example','https://humix.example?x=1','https://humix.example#x','https://humix.example:8443']as$url)rejects(fn()=>HumixAi_WP_Client::base($url));
check(HumixAi_WP_Client::base('https://humix.example/sub/')==='https://humix.example/sub','Subpath');
$p=['text'=>'A sample draft long enough.','action'=>'combo','language'=>'bn','external_processing_consent'=>true,'idempotency_key'=>'test-1','document_id'=>'untrusted'];
$valid=HumixAi_WP_Client::payload($p);check(!isset($valid['document_id']),'Unexpected fields removed');
foreach([['external_processing_consent'=>false],['language'=>'xx'],['action'=>'delete'],['text'=>'short'],['idempotency_key'=>'bad key'],['text'=>str_repeat('x',60001)]]as$change)rejects(fn()=>HumixAi_WP_Client::payload(array_replace($p,$change)));
HumixAi_WP_Client::request('/api/v1/jobs','POST',$valid);[$url,$args]=$GLOBALS['request'];
check($url==='https://humix.example/api/v1/jobs','Endpoint');check($args['redirection']===0&&$args['sslverify']===true,'Redirect and TLS protection');check($args['limit_response_size']===1000000,'Response bound');check($args['headers']['Authorization']==='Bearer private-token','Personal token');
$GLOBALS['uid']=8;rejects(fn()=>HumixAi_WP_Client::request('/api/v1/jobs','GET'));$GLOBALS['uid']=7;
foreach([['code'=>302,'body'=>'{}'],['code'=>401,'body'=>'secret error'],['code'=>200,'body'=>'not json'],['code'=>200,'body'=>'{"success":false}']]as$response){$GLOBALS['response']=$response;rejects(fn()=>HumixAi_WP_Client::request('/api/v1/jobs','GET'));}
rejects(fn()=>HumixAi_WP_Client::request('/api/v1/documents','GET'));
check(!HumixAi_WP_Client::jobId('../other'),'Job path validation');
echo "WordPress client contract checks passed.\n";
