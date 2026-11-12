<?php
$messages = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/pagination.php');
$catalog = json_decode(file_get_contents(lang_path('ar.json')), true);
array_walk_recursive($messages, function (&$value) use ($catalog) { if (is_string($value)) $value = $catalog[$value] ?? $value; });
return $messages;
