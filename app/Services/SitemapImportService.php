<?php
namespace App\Services;
use DOMDocument;
use DOMElement;
use Illuminate\Validation\ValidationException;
class SitemapImportService extends UrlImportService {
 protected function contentTypes():array{return ['application/xml','text/xml','text/plain'];}
 public function preview(string $url):array{
  $download=$this->download($url);$xml=$download['body'];$origin=$download['target'];
  if(preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i',$xml))$this->invalid(__('Sitemaps with document types or XML entities are not supported.'));
  $previous=libxml_use_internal_errors(true);
  try{
   $dom=new DOMDocument();$dom->resolveExternals=false;$dom->substituteEntities=false;
   if(!$dom->loadXML($xml,LIBXML_NONET|LIBXML_COMPACT))$this->invalid(__('The sitemap XML is invalid.'));
   $root=$dom->documentElement;
   if(!$root || !in_array($root->localName,['urlset','sitemapindex'],true) || !in_array($root->namespaceURI,[null,'','http://www.sitemaps.org/schemas/sitemap/0.9'],true))$this->invalid(__('Use a standard XML URL sitemap or sitemap index.'));
   $index=$root->localName==='sitemapindex';$urls=[];$skipped=0;$truncated=false;
   foreach($root->childNodes as $entry){
    if(!$entry instanceof DOMElement || $entry->localName!==($index?'sitemap':'url') || $entry->namespaceURI!==$root->namespaceURI)continue;
    $loc=null;foreach($entry->childNodes as $child)if($child instanceof DOMElement && $child->localName==='loc' && $child->namespaceURI===$root->namespaceURI){$loc=trim($child->textContent);break;}
    if(!$loc || strlen($loc)>2048){$skipped++;continue;}
    try{$target=$this->target($loc);}catch(ValidationException){$skipped++;continue;}
    if($target['host']!==$origin['host'] || $target['scheme']!==$origin['scheme']){$skipped++;continue;}
    if(isset($urls[$target['url']]))continue;
    if(count($urls)>=200){$truncated=true;break;}
    $urls[$target['url']]=true;
   }
   return ['source_url'=>$origin['url'],'kind'=>$index?'index':'pages','urls'=>array_keys($urls),'skipped'=>$skipped,'truncated'=>$truncated];
  }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
 }
}
