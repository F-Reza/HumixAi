(() => {
'use strict';
const root=document.getElementById('writingRecorder');if(!root)return;
const $=id=>document.getElementById(id),t=window.HumixI18n.t;
const endpoint='/writing-records/'+encodeURIComponent(root.dataset.recordId), editor=$('recordingText');
let active=false, timer=null, pending=null, sequence=Number(root.dataset.nextSequence), saved=editor.value, kinds=new Set(), frames=[], playback=null;
const status=(key)=>{$('recordingStatus').textContent=t(key);};
async function request(path,method='GET',body){const response=await fetch(endpoint+path,{method,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:body?JSON.stringify(body):undefined});const data=await response.json();if(!response.ok)throw new Error(Object.values(data.errors||{}).flat()[0]||data.message||t('Request failed.'));return data;}
function controls(){ $('startRecording').disabled=active; $('stopRecording').disabled=!active; }
function schedule(){if(active&&!timer)timer=setTimeout(()=>{timer=null;flush().catch(failed);},5000);}
function failed(error){active=false;clearTimeout(timer);timer=null;controls();$('recordingStatus').textContent=error.message;}
async function flush(){
 if(pending){await pending;return flush();}
 if(editor.value===saved)return;
 const text=editor.value, kind=kinds.size===1?[...kinds][0]:kinds.size?'mixed':'unknown';kinds=new Set();
 pending=request('/frames','POST',{event_id:crypto.randomUUID(),expected_sequence:sequence,kind,recording_text:text}).then(result=>{sequence=result.sequence+1;saved=text;status('Snapshot saved.');}).finally(()=>{pending=null;});
 await pending;if(editor.value!==saved)schedule();
}
editor.addEventListener('input',event=>{if(!active)return;const type=event.inputType||'';let kind=type==='insertFromPaste'?'paste':type==='insertFromDrop'?'drop':type==='deleteByCut'?'cut':type==='historyUndo'?'undo':type==='historyRedo'?'redo':type==='insertText'||type.startsWith('deleteContent')?'typing':'unknown';if(event.isTrusted===false)kind='unknown';kinds.add(kind);schedule();});
$('startRecording').onclick=async()=>{
 if(!$('recordingConsent').checked){status('Confirm recording consent before starting.');return;}
 $('startRecording').disabled=true;
 try{await request('/state','PATCH',{active:true,storage_consent:true});active=true;kinds=new Set(editor.value!==saved?['unknown']:[]);controls();status('Recording your draft snapshots.');if(editor.value!==saved)schedule();}catch(e){failed(e);}
};
$('stopRecording').onclick=async()=>{
 active=false;clearTimeout(timer);timer=null;$('stopRecording').disabled=true;$('startRecording').disabled=true;
 try{await flush();await request('/state','PATCH',{active:false});status('Recording is paused.');}catch(e){failed(e);}finally{controls();}
};
$('copyRecordedDraft').onclick=async()=>{try{await navigator.clipboard.writeText(editor.value);status('Draft copied.');}catch(_){status('Copy failed. Select and copy the draft manually.');}};
function renderFrame(){const frame=frames[Number($('replayPosition').value)];if(!frame)return;$('replayText').textContent=frame.text;$('replayCaption').textContent=`${frame.sequence+1} / ${frames.length} | ${t(frame.kind)} | ${new Date(frame.created_at).toLocaleString(document.documentElement.lang)}`;}
$('replayPosition').oninput=renderFrame;
$('loadReplay').onclick=async()=>{try{const data=await request('/frames');frames=data.frames;$('replayPosition').max=Math.max(0,frames.length-1);$('replayPosition').value=0;$('replayPanel').hidden=false;$('recordingNotice').textContent=data.notice;$('contributionCounts').replaceChildren();for(const [kind,count]of Object.entries(data.event_counts)){const p=document.createElement('p');p.textContent=t(kind)+': '+count;$('contributionCounts').append(p);}renderFrame();}catch(e){$('recordingStatus').textContent=e.message;}};
$('playReplay').onclick=()=>{clearInterval(playback);if(!frames.length)return;if(Number($('replayPosition').value)>=frames.length-1)$('replayPosition').value=0;renderFrame();playback=setInterval(()=>{const next=Number($('replayPosition').value)+1;if(next>=frames.length){clearInterval(playback);return;}$('replayPosition').value=next;renderFrame();},1000);};
$('pauseReplay').onclick=()=>clearInterval(playback);
window.addEventListener('beforeunload',event=>{if(pending||editor.value!==saved){event.preventDefault();event.returnValue='';}});
})();
