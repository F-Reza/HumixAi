<?php
namespace App\Services\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
class SystemSettingsService {
    public const SCHEMA = [
        'allow_registration'=>['boolean'], 'url_import_enabled'=>['boolean'],
        'daily_word_limit'=>['integer','min:0','max:1000000'], 'max_batch_size'=>['integer','min:1','max:50'],
        'max_characters'=>['integer','min:100','max:60000'], 'job_retention_hours'=>['integer','min:1','max:720'],
    ];
    public const PROVIDERS = ['gptzero'=>'services.gptzero.api_key','gemini'=>'services.gemini.api_key','walter'=>'services.walter.api_key'];
    private array $defaults = [];
    public function apply(): void {
        foreach (array_keys(self::SCHEMA) as $key) { $path = 'studio.'.$key; if (!array_key_exists($path, $this->defaults)) $this->defaults[$path] = config($path); config([$path=>$this->defaults[$path]]); }
        foreach (self::PROVIDERS as $path) { if (!array_key_exists($path, $this->defaults)) $this->defaults[$path] = config($path); config([$path=>$this->defaults[$path]]); }
        if (!Schema::hasTable('system_settings')) return;
        foreach (DB::table('system_settings')->get() as $row) {
            if (array_key_exists($row->key, self::SCHEMA)) config(['studio.'.$row->key=>json_decode($row->value,true)]);
            if (str_starts_with($row->key,'provider.')) { $id = substr($row->key,9); if (isset(self::PROVIDERS[$id])) config([self::PROVIDERS[$id]=>Crypt::decryptString($row->value)]); }
        }
    }
    public function audit(?int $actor, string $action, string $target = '', array $metadata = []): void {
        DB::table('admin_audits')->insert(['actor_id'=>$actor,'action'=>$action,'target'=>$target,'metadata'=>json_encode($metadata), 'created_at'=>now()]);
    }
}
