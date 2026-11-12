(() => {
'use strict';
const root=document.getElementById('humixai-controls');if(!root)return;
const config=window.HumixWordPress,t=key=>config.catalog[key]||key;
root.dir=['ar','ur'].includes(config.locale)?'rtl':'ltr';
function el(tag,text){const e=document.createElement(tag);if(text)e.textContent=t(text);return e;}
function button(label,handler){const b=el('button',label);b.type='button';b.className='button';b.onclick=handler;root.append(b);return b;}
function select(label,items){const l=el('label',label),s=el('select');for(const [value,name]of items){const o=el('option',name);o.value=value;s.append(o);}l.append(s);root.append(l);return s;}
root.append(el('p','Import or paste a draft, review the text, then explicitly submit it. Your WordPress post is never changed automatically.'));
const source=el('textarea');source.rows=10;source.maxLength=60000;source.dir='auto';source.style.width='100%';source.setAttribute('aria-label',t('Your draft'));root.append(source);
button('Import current editor text',()=>{
 let html='';
 try{html=window.wp?.data?.select('core/editor')?.getEditedPostContent()||'';}catch(_){}
 if(!html)html=window.tinymce?.get('content')?.getContent()||document.getElementById('content')?.value||'';
 const doc=new DOMParser().parseFromString(html,'text/html');doc.querySelectorAll('script,style,noscript').forEach(e=>e.remove());
 doc.querySelectorAll('p,div,li,h1,h2,h3,h4,blockquote,br').forEach(e=>e.append(doc.createTextNode('\n')));
 source.value=(doc.body.textContent||'').trim();status.textContent=t('Review the imported plain text before submitting. Formatting and embedded media are not sent.');
});
const language=select('Language',[['en','English'],['bn','Bangla'],['hi','Hindi'],['ur','Urdu'],['ar','Arabic'],['ne','Nepali']]);
const action=select('Action',[['detect','Detect'],['humanize','Humanize'],['combo','Detect and humanize']]);
const label=el('label'),consent=el('input');consent.type='checkbox';label.append(consent,el('span','I authorize sending this draft to HumixAi and its configured AI providers for this operation.'));root.append(el('p'),label,el('p'));
const status=el('p');status.setAttribute('role','status');root.append(status);
const result=el('pre');result.style.whiteSpace='pre-wrap';result.style.overflowWrap='anywhere';
const output=el('textarea');output.readOnly=true;output.rows=10;output.dir='auto';output.style.width='100%';output.setAttribute('aria-label',t('Rewritten draft'));
let job=null,epoch=0,signature='',submission=null;
async function request(operation,extra){
 const body=new URLSearchParams({action:'humixai',nonce:config.nonce,operation,...extra});
 const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),30000);
 try{const response=await fetch(config.ajax,{method:'POST',credentials:'same-origin',redirect:'error',signal:controller.signal,body});const json=await response.json();if(!response.ok||json.success!==true)throw new Error(t('The request failed. Check your connection, token scopes, allowance and HumixAi queue worker. A submitted job may still be running.'));return json.data;}finally{clearTimeout(timer);}
}
function display(data){
 result.textContent=JSON.stringify(data.result??{status:data.status,error:data.error},null,2);
 output.value=typeof data.result?.humanized_text==='string'?data.result.humanized_text:'';
 status.textContent=t(data.status||'unknown');
}
async function poll(turn){
 for(let n=0;n<60&&turn===epoch;n++){
  const data=await request('poll',{job});if(turn!==epoch)return;display(data);
  if(!['queued','running'].includes(data.status))return;
  await new Promise(resolve=>setTimeout(resolve,3000));
 }
 if(turn===epoch)status.textContent=t('Polling paused. Use Check result to continue. The server job may still be running.');
}
const submit=button('Run HumixAi',async()=>{
 if(!consent.checked){status.textContent=t('Confirm processing consent.');return;}
 if(source.value.trim().length<15||[...source.value].length>60000){status.textContent=t('Use 15 to 60,000 characters.');return;}
 const payload={text:source.value,action:action.value,language:language.value,external_processing_consent:true};
 const next=JSON.stringify(payload);if(signature!==next){signature=next;submission={...payload,idempotency_key:crypto.randomUUID()};}
 const turn=++epoch;submit.disabled=true;result.textContent='';output.value='';status.textContent=t('queued');
 try{const data=await request('submit',{payload:JSON.stringify(submission)});if(turn!==epoch)return;job=data.id;display(data);if(['queued','running'].includes(data.status))await poll(turn);}
 catch(e){if(turn===epoch)status.textContent=e.message;}finally{submit.disabled=false;}
});
button('Check result',async()=>{if(!job)return;const turn=++epoch;try{await poll(turn);}catch(e){if(turn===epoch)status.textContent=e.message;}});
button('Stop waiting',()=>{epoch++;status.textContent=t('Polling paused. Use Check result to continue. The server job may still be running.');});
root.append(el('p','Provider probabilities are estimates, not proof of authorship. Language-specific accuracy has not been evaluated by HumixAi.'),result,output);
button('Copy draft',async()=>{try{await navigator.clipboard.writeText(output.value);status.textContent=t('Draft copied.');}catch(_){status.textContent=t('Copy failed. Select and copy the draft manually.');}});
})();
