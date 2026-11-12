const test=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs');
function mount(fail=false){
 const calls=[],all=[];let count=0;
 function element(tag){const e={tag,value:'',children:[],style:{},append(...x){this.children.push(...x);if(this.tag==='select'&&!this.value)this.value=x[0].value;},setAttribute(){}};all.push(e);return e;}
 const root=element('div');
 vm.runInNewContext(fs.readFileSync('integrations/wordpress/humixai/companion.js','utf8'),{document:{getElementById:id=>id==='humixai-controls'?root:null,createElement:element},window:{HumixWordPress:{catalog:{},locale:'en',ajax:'/wp-admin/admin-ajax.php',nonce:'nonce'}},crypto:{randomUUID:()=> 'submission-'+(++count)},URLSearchParams,AbortController,setTimeout,clearTimeout,navigator:{clipboard:{writeText:async()=>{}}},fetch:async(url,args)=>{calls.push(Object.fromEntries(args.body));if(fail){fail=false;throw new Error('Network error');}return{ok:true,json:async()=>({success:true,data:{id:'job',status:'completed',result:{humanized_text:'<script>example</script>',class_probabilities:{human:null,mixed:null,ai:null}}}})}}});
 return{calls,all,button:label=>all.find(e=>e.tag==='button'&&e.textContent===label),source:all.find(e=>e.tag==='textarea'),consent:all.find(e=>e.tag==='input')};
}
test('WordPress requires explicit consent and preserves source',async()=>{
 const m=mount();m.source.value='A sufficiently long original draft.';
 await m.button('Run HumixAi').onclick();assert.equal(m.calls.length,0);
 m.consent.checked=true;await m.button('Run HumixAi').onclick();assert.equal(m.calls.length,1);
 const body=JSON.parse(m.calls[0].payload);assert.equal(body.external_processing_consent,true);assert.equal(body.language,'en');assert.equal(body.action,'detect');
 assert.equal(m.source.value,'A sufficiently long original draft.');assert.equal(m.all.filter(e=>e.tag==='textarea')[1].value,'<script>example</script>');
});
test('unchanged retries reuse submission key, edits create a new key',async()=>{
 const m=mount(true);m.source.value='A sufficiently long original draft.';m.consent.checked=true;
 await m.button('Run HumixAi').onclick();await m.button('Run HumixAi').onclick();
 assert.equal(JSON.parse(m.calls[0].payload).idempotency_key,JSON.parse(m.calls[1].payload).idempotency_key);
 m.source.value+=' Changed.';await m.button('Run HumixAi').onclick();assert.notEqual(JSON.parse(m.calls[1].payload).idempotency_key,JSON.parse(m.calls[2].payload).idempotency_key);
});
