const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs');
function mount(){
 const elements={},calls=[];let timer;
 const element=()=>({value:'',dataset:{},events:{},children:[],addEventListener(k,v){this.events[k]=v},replaceChildren(){this.children=[]},append(p){this.children.push(p)}});
 const get=id=>elements[id]??=element();get('writingRecorder').dataset={recordId:'id',nextSequence:'1'};get('recordingText').value='Initial';
 vm.runInNewContext(fs.readFileSync('public/assets/writing-recorder.js','utf8'),{document:{getElementById:get,querySelector:()=>({content:'csrf'}),createElement:element,documentElement:{lang:'en'}},window:{HumixI18n:{t:k=>k},addEventListener(){}},crypto:{randomUUID:()=> 'uuid'},setTimeout:f=>{timer=f;return 1},clearTimeout:()=>{timer=null},setInterval:()=>1,clearInterval(){},navigator:{clipboard:{writeText:async()=>{}}},fetch:async(url,options)=>{calls.push({url,...options,body:options.body&&JSON.parse(options.body)});return {ok:true,json:async()=>options.method==='POST'?{sequence:1}:options.method==='GET'?{frames:[{sequence:0,kind:'initial',text:'<script>bad()</script>',created_at:'2026-11-11'}],event_counts:{initial:1},notice:'Not proof'}:{active:true}}}});
 return {get,calls,runTimer:async()=>{timer?.();await new Promise(r=>setImmediate(r))}};
}
test('recording requires consent, saves typed snapshots and pauses',async()=>{
 const {get,calls,runTimer}=mount();get('recordingText').events.input({inputType:'insertText',isTrusted:true});assert.equal(calls.length,0);
 await get('startRecording').onclick();assert.equal(calls.length,0);
 get('recordingConsent').checked=true;await get('startRecording').onclick();get('recordingText').value='Typed';get('recordingText').events.input({inputType:'insertText',isTrusted:true});await runTimer();
 assert.equal(calls[1].body.kind,'typing');assert.equal(calls[1].body.recording_text,'Typed');
 await get('stopRecording').onclick();assert.equal(calls.at(-1).body.active,false);
});
test('replay displays draft as plain text',async()=>{const {get}=mount();await get('loadReplay').onclick();assert.equal(get('replayText').textContent,'<script>bad()</script>');assert.equal(get('recordingNotice').textContent,'Not proof');});
