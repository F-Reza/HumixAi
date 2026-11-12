<?php
$messages = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
$catalog = json_decode(file_get_contents(lang_path('ur.json')), true);
array_walk_recursive($messages, function (&$value) use ($catalog) { if (is_string($value)) $value = $catalog[$value] ?? $value; });
$labels = ['name'=>'Name','email'=>'Email','password'=>'Password','current_password'=>'Current password','locale'=>'Interface language','text'=>'Original draft','quota_override'=>'Daily word override','date'=>'Date','reason'=>'Reason','words_delta'=>'Word adjustment'];
foreach ($labels as $key => $label) $messages['attributes'][$key] = $catalog[$label] ?? $label;
return $messages;
