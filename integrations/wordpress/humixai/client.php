<?php
if (!defined('ABSPATH')) exit;
final class HumixAi_WP_Client {
 public static function jobId($id):bool { return is_string($id) && preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i',$id)===1; }
 public static function base($url):string {
  if (!is_string($url)) throw new InvalidArgumentException('Configure the HumixAi HTTPS URL.');
  $p=parse_url($url);
  if (!$p || ($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || (isset($p['port']) && $p['port']!==443)) throw new InvalidArgumentException('Use an HTTPS URL without credentials, query, fragment or a nonstandard port.');
  return rtrim($url,'/');
 }
 public static function payload($p):array {
  if (!is_array($p) || ($p['external_processing_consent']??null)!==true) throw new InvalidArgumentException('Confirm processing consent.');
  if (!is_string($p['text']??null) || strlen($p['text'])>240000 || !preg_match('/\S/u',$p['text']) || mb_strlen($p['text'])<15 || mb_strlen($p['text'])>60000) throw new InvalidArgumentException('Use 15 to 60,000 characters.');
  if (!in_array($p['action']??null,['detect','humanize','combo'],true) || !in_array($p['language']??null,['en','bn','hi','ur','ar','ne'],true)) throw new InvalidArgumentException('Choose a supported action and language.');
  if (!is_string($p['idempotency_key']??null) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$p['idempotency_key'])) throw new InvalidArgumentException('Invalid submission identifier.');
  return array_intersect_key($p,array_flip(['text','action','language','external_processing_consent','idempotency_key']))+['mode'=>'professional','strength'=>'balanced','length'=>'similar'];
 }
 public static function request(string $path,string $method,array $payload=[]):array {
  if (!preg_match('~^/api/v1/jobs(?:/[a-f0-9-]{36})?$~i',$path) || !in_array($method,['GET','POST'],true)) throw new RuntimeException('Unsupported request.');
  $base=self::base(defined('HUMIXAI_URL')?HUMIXAI_URL:'');
  $tokens=defined('HUMIXAI_TOKENS')?HUMIXAI_TOKENS:[];
  $token=is_array($tokens)?($tokens[get_current_user_id()]??''):'';
  if (!is_string($token) || !$token || strlen($token)>200 || preg_match('/\s/',$token)) throw new InvalidArgumentException('Ask the administrator to configure your personal HumixAi API token.');
  $args=['method'=>$method,'timeout'=>25,'redirection'=>0,'limit_response_size'=>1000000,'sslverify'=>true,'headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json','Content-Type'=>'application/json']];
  if ($method==='POST') $args['body']=wp_json_encode($payload);
  $response=wp_safe_remote_request($base.$path,$args);
  if (is_wp_error($response)) throw new RuntimeException('Connection failed.');
  $status=wp_remote_retrieve_response_code($response);
  if ($status<200 || $status>=300) throw new RuntimeException('API rejected request.');
  $json=json_decode(wp_remote_retrieve_body($response),true);
  if (!is_array($json) || ($json['success']??false)!==true || !is_array($json['data']??null)) throw new RuntimeException('Invalid API response.');
  return $json['data'];
 }
}
