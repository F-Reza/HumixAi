<?php
$messages = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/passwords.php');
$catalog = json_decode(file_get_contents(lang_path('ne.json')), true);
array_walk_recursive($messages, function (&$value) use ($catalog) { if (is_string($value)) $value = $catalog[$value] ?? $value; });
return $messages;
