<?php
namespace App\Services;
class LocalPatternService {
 public function analyze(string $text,string $language='auto'):array {
  $analysis=new TextAnalysisService();$result=(new AiDetectorService($analysis))->analyzeLocal($text,$language);
  $sentences=$analysis->sentences($text);$counts=array_map(fn($s)=>count($analysis->words($s['text'])),$sentences);$counts=array_values(array_filter($counts));
  $eligible=count($counts)>=3 && $result['word_count']>=30;
  $mean=count($counts)?array_sum($counts)/count($counts):0;
  $sd=$mean?sqrt(array_sum(array_map(fn($n)=>($n-$mean)**2,$counts))/count($counts)):0;
  $near=0;
  foreach($sentences as $sentence)if(abs(count($analysis->words($sentence['text']))-$mean)<=max(1,$mean*.1))$near++;
  foreach($sentences as &$sentence){
   $count=count($analysis->words($sentence['text']));
   $regular=$eligible && $near>=3 && abs($count-$mean)<=max(1,$mean*.1);
   $longer=$eligible && $count>=15 && $count>$mean*1.25;
   $sentence['highlight']=$eligible && ($count>30 || $regular || $longer);
   $sentence['reason']=$count>30?__('This sentence exceeds 30 words. Review readability; this is not evidence of AI authorship.'):($longer?__('This sentence is over 25% longer than the draft average and has at least 15 words. Review its structure; this is not an AI-authorship signal.'):($regular?__('This sentence is close to the average length, alongside at least two others. Similar lengths are a local pattern, not evidence of AI authorship.'):__('No local sentence-length flag. This does not establish human authorship.')));
  }
  unset($sentence);
  return array_replace($result,['sentences'=>$sentences,'status'=>$eligible?'completed':'insufficient_text','provider'=>'local','engine'=>'local-pattern-analysis','model_version'=>'sentence-regularity-v1','score_type'=>'not_an_authorship_score','score_label'=>__('Local writing patterns'),'overall_score'=>null,'analyzed_characters'=>mb_strlen($text),'language_notice'=>__('Local patterns cannot determine whether AI wrote this text. No AI probability or human/AI verdict is produced. All six languages need independent evaluation.'),'message'=>$eligible?__('Analysis ran on the HumixAi server without an external API or API key.'):__('Use at least 30 words and three sentences for local pattern analysis.'),'local_patterns'=>['sentence_count'=>count($counts),'mean_sentence_words'=>round($mean,2),'sentence_length_standard_deviation'=>round($sd,2),'sentence_regularity_index'=>$eligible?round(100/(1+$sd/$mean),1):null,'definition'=>__('Sentence regularity index = 100 / (1 + standard deviation / mean sentence length). Higher values mean more uniform sentence lengths, not a higher probability of AI authorship.')]]);
 }
}
