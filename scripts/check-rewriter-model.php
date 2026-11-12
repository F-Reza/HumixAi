<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();app(App\Services\Admin\SystemSettingsService::class)->apply();
$key=config('services.gemini.api_key');$base=rtrim(config('services.gemini.base_url'),'/');$model=config('services.gemini.model');
echo 'Configured model: '.$model.PHP_EOL;
foreach(array_unique([$model,'gemini-3.1-flash-lite']) as $candidate){
 try{$r=Illuminate\Support\Facades\Http::withHeaders(['x-goog-api-key'=>$key])->withOptions(['allow_redirects'=>false])->connectTimeout(10)->timeout(30)->get($base.'/models/'.rawurlencode($candidate));echo $candidate.': HTTP '.$r->status().' status '.($r->json('error.status')??'OK').PHP_EOL;}catch(Throwable $e){echo $candidate.': transport failure ('.get_class($e).')'.PHP_EOL;}
}
if(in_array('--generate',$argv,true)){
 if(in_array('--alternate',$argv,true))config(['services.gemini.model'=>'gemini-3.1-flash-lite']);
 Illuminate\Support\Facades\Http::globalResponseMiddleware(function($r){echo 'Generation HTTP: '.$r->getStatusCode().PHP_EOL; if($r->getStatusCode()>=400){$j=json_decode((string)$r->getBody(),true);echo 'Provider status: '.($j['error']['status']??'unknown').PHP_EOL; $message=$j['error']['message']??'';foreach(['quota','not found','not supported','permission','API key','overloaded','unavailable']as$term)if(stripos($message,$term)!==false)echo 'Diagnostic category: '.$term.PHP_EOL;}return $r;});
 try{$text=app(App\Services\GeminiApiService::class)->generateText('Rewrite this test sentence clearly: Good writing makes complex ideas easy to understand.','Return only the rewritten sentence.');echo 'Generation succeeded; output characters: '.mb_strlen($text).PHP_EOL;}catch(Throwable $e){echo 'Generation failed: '.get_class($e).PHP_EOL;}
}

if(in_array('--pipeline',$argv,true)){
 try{$r=app(App\Services\AiHumanizerService::class)->humanize('Good writing makes complex ideas easier to understand. A useful first draft gives your thoughts a place to start. Read it with fresh eyes and keep the details that matter. Clear examples help readers follow a difficult explanation without losing the main point. Give each paragraph a purpose and connect it naturally to the next. Choose familiar words whenever they express the idea accurately. Remove repetition that adds no meaning, but keep the context readers need. Read the draft aloud to notice awkward phrasing and sudden changes in tone. A final review should check that the revision still says what the writer intended.', 'professional',in_array('--combo',$argv,true),['language'=>'en','length'=>'similar']);echo 'Pipeline output characters: '.mb_strlen($r['humanized_text']??'').PHP_EOL;if(in_array('--combo',$argv,true))echo 'Scan statuses: '.($r['original_detection']['status']??'none').' / '.($r['verification']['status']??'none').'; comparable: '.(($r['comparable']??false)?'yes':'no').PHP_EOL;echo 'Preservation passed: '.(($r['preservation']['passed']??false)?'yes':'no').PHP_EOL;}catch(Throwable $e){echo 'Pipeline failed: '.get_class($e).PHP_EOL;if($e instanceof App\Services\Exceptions\HumanizationException)echo $e->getMessage().PHP_EOL;exit(1);}
}
