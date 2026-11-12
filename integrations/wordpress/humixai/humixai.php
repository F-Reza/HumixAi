<?php
/**
 * Plugin Name: HumixAi Writing Companion
 * Description: Explicit-consent draft detection and rewriting through your HumixAi workspace.
 * Version: 1.0.0
 * Requires PHP: 8.0
 * Text Domain: humixai
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/client.php';
add_action('admin_menu', function () { add_management_page('HumixAi', 'HumixAi', 'edit_posts', 'humixai', 'humixai_panel'); });
add_action('add_meta_boxes', function () { foreach (['post','page'] as $type) add_meta_box('humixai', 'HumixAi', 'humixai_panel', $type, 'normal', 'default', ['__block_editor_compatible_meta_box'=>true]); });
function humixai_panel() {
 if (!current_user_can('edit_posts')) return;
 echo '<div id="humixai-panel"><p>HumixAi</p><div id="humixai-controls"></div></div>';
}
add_action('admin_enqueue_scripts', function ($hook) {
 if (!in_array($hook, ['post.php','post-new.php','tools_page_humixai'], true) || !current_user_can('edit_posts')) return;
 wp_enqueue_script('humixai', plugins_url('companion.js', __FILE__), [], '1.0.0', true);
 $locale = substr(get_user_locale(),0,2);
 if (!in_array($locale,['en','bn','hi','ur','ar','ne'],true)) $locale='en';
 $catalog = json_decode(file_get_contents(__DIR__.'/lang/'.$locale.'.json'),true);
 wp_localize_script('humixai','HumixWordPress',['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('humixai'),'locale'=>$locale,'catalog'=>$catalog]);
});
add_action('wp_ajax_humixai', function () {
 check_ajax_referer('humixai','nonce');
 if (!current_user_can('edit_posts')) wp_send_json_error(['message'=>'Permission denied.'],403);
 try {
  $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? $_POST['operation'] : '';
  if ($operation==='submit') {
   $payload=json_decode(wp_unslash($_POST['payload']??''),true);
   $payload=HumixAi_WP_Client::payload($payload);
   $data=HumixAi_WP_Client::request('/api/v1/jobs','POST',$payload);
   if (!isset($data['id']) || !HumixAi_WP_Client::jobId($data['id'])) throw new RuntimeException('Invalid job response.');
   set_transient('humixai_'.get_current_user_id().'_'.$data['id'],true,DAY_IN_SECONDS);
  } elseif ($operation==='poll') {
   $id=$_POST['job']??'';
   if (!HumixAi_WP_Client::jobId($id) || !get_transient('humixai_'.get_current_user_id().'_'.$id)) throw new RuntimeException('Job unavailable.');
   $data=HumixAi_WP_Client::request('/api/v1/jobs/'.$id,'GET');
  } else throw new RuntimeException('Unsupported operation.');
  wp_send_json_success($data);
 } catch (Throwable $e) { wp_send_json_error(['message'=>$e instanceof InvalidArgumentException?$e->getMessage():'HumixAi could not complete this request. Check configuration, token scopes, allowance and queue worker. A submitted job may still run; check HumixAi before submitting again.'],400); }
});
