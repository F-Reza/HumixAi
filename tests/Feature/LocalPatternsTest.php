<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\Http;
class LocalPatternsTest extends TestCase {
 public function test_no_key_no_consent_no_external_call_and_no_authorship_probability():void {
  Http::preventStrayRequests(); config(['services.gptzero.api_key'=>null]);
  $text=str_repeat('Careful writers review their drafts and improve each sentence with clear examples. ',4);
  $r=$this->postJson('/ai-tools/local-patterns',['text'=>$text,'language'=>'en']);
  $r->assertOk()->assertJsonPath('data.provider','local')->assertJsonPath('data.status','completed')->assertJsonPath('data.overall_score',null)->assertJsonPath('data.class_probabilities.ai',null)->assertJsonPath('data.local_patterns.sentence_regularity_index',100);
  $r->assertJsonPath('data.sentences.0.highlight',true)->assertJsonPath('data.sentences.0.score',null);
  Http::assertNothingSent();
 }
 public function test_short_input_has_no_regularity_score():void {
  $this->postJson('/ai-tools/local-patterns',['text'=>'A short sample is not enough.'])->assertOk()->assertJsonPath('data.status','insufficient_text')->assertJsonPath('data.local_patterns.sentence_regularity_index',null);
 }
 public function test_local_choice_never_uses_a_configured_provider():void {
  Http::preventStrayRequests();config(['services.gptzero.api_key'=>'private-key']);
  $this->postJson('/ai-tools/local-patterns',['text'=>str_repeat('A useful draft needs thoughtful editing and clear examples for its readers. ',4),'language'=>'en'])->assertOk();Http::assertNothingSent();
 }
}
