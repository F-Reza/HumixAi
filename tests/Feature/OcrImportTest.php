<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Services\{OcrService,DocumentImportService};
use Illuminate\Http\UploadedFile;
class OcrImportTest extends TestCase{
 public function test_unavailable_ocr_never_advertises_image_support():void{config(['studio.ocr_enabled'=>false]);$caps=app(DocumentImportService::class)->capabilities();$this->assertFalse($caps['ocr']);$this->assertNotContains('png',$caps['formats']);}
 public function test_installed_packs_are_mapped_and_image_preview_is_explicit():void{
  config(['studio.ocr_enabled'=>true]);
  $ocr=new class extends OcrService{public array $commands=[];protected function run(array $args,int $timeout,int $maxBytes):string{$this->commands[]=$args;return $args[0]==='--list-langs'?"List of available languages (3):\neng\nben\nara":"Recognized text 123.";}};
  $this->app->instance(OcrService::class,$ocr);$this->assertSame(['en','bn','ar'],$ocr->languages());
  $file=UploadedFile::fake()->createWithContent('scan.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII='));
  $result=app(DocumentImportService::class)->preview($file,'bn');$this->assertSame('Recognized text 123.',$result['text']);$this->assertStringContainsString('OCR',$result['warnings'][0]);$this->assertSame('ben',$ocr->commands[1][3]);
 }
 public function test_an_image_must_not_silently_fall_back_to_english():void{
  $ocr=new class extends OcrService{public function languages():array{return ['en'];}};
  $this->expectException(\Illuminate\Validation\ValidationException::class);$ocr->extract('not-read','hi');
 }
 public function test_non_image_content_is_rejected_before_ocr_execution():void{
  $ocr=new class extends OcrService{public function languages():array{return ['en'];}protected function run(array $a,int $t,int $m):string{throw new \LogicException('Should not run');}};
  $file=UploadedFile::fake()->createWithContent('fake.png','Not an image');$this->expectException(\Illuminate\Validation\ValidationException::class);$ocr->extract($file->getRealPath(),'en');
 }
}
