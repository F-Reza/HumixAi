<?php
namespace App\Services;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
class OcrService {
 private const LANGUAGES=['en'=>'eng','bn'=>'ben','hi'=>'hin','ur'=>'urd','ar'=>'ara','ne'=>'nep'];
 private ?array $available=null;
 public function languages():array{
  if($this->available!==null)return $this->available;
  $this->available=[];if(!config('studio.ocr_enabled',false))return [];
  try{$output=$this->run(['--list-langs'],5,65536);$installed=preg_split('/\s+/',$output);foreach(self::LANGUAGES as $locale=>$pack)if(in_array($pack,$installed,true))$this->available[]=$locale;}catch(\Throwable){}
  return $this->available;
 }
 public function extract(string $path,string $language):string{
  if(!in_array($language,$this->languages(),true))throw ValidationException::withMessages(['language'=>__('Select an installed OCR language. Auto-detection is not available for images.')]);
  $size=@getimagesize($path);
  if(!$size || !in_array($size[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG],true) || $size[0]<1 || $size[1]<1 || $size[0]>10000 || $size[1]>10000 || $size[0]*$size[1]>20000000)throw ValidationException::withMessages(['file'=>__('Choose a valid PNG or JPEG image no larger than 20 megapixels.')]);
  try{return trim($this->run([$path,'stdout','-l',self::LANGUAGES[$language],'--psm','3'],30,max(4096,(int)config('studio.max_characters',60000)*4)));}
  catch(\Throwable){throw ValidationException::withMessages(['file'=>__('OCR could not read this image within the processing limits.')]);}
 }
 protected function run(array $arguments,int $timeout,int $maxBytes):string{
  $process=new Process([(string)config('studio.tesseract_path','tesseract'),...$arguments],null,['OMP_THREAD_LIMIT'=>'1']);
  $process->setTimeout($timeout);$text='';$bytes=0;
  $process->run(function($type,$buffer)use(&$text,&$bytes,$maxBytes,$process){$bytes+=strlen($buffer);if($bytes>$maxBytes){$process->stop(0);throw new \RuntimeException('OCR output limit');}if($type===Process::OUT)$text.=$buffer;});
  if(!$process->isSuccessful())throw new \RuntimeException('OCR failed');return $text;
 }
}
