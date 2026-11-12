<?php
require __DIR__.'/../vendor/autoload.php';$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'Pending queue: '.Illuminate\Support\Facades\DB::table('jobs')->count().PHP_EOL;
foreach(App\Models\StudioJob::latest()->limit(5)->get() as $j)echo json_encode(['id'=>$j->id,'status'=>$j->status,'stage'=>$j->stage,'created'=>$j->created_at,'completed'=>$j->completed_at,'has_output'=>!empty($j->result['humanized_text']),'error'=>$j->error]).PHP_EOL;
