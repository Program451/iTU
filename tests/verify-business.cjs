const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const presets=JSON.parse(fs.readFileSync('public/business-presets.json','utf8'));
const context=vm.createContext({BOOT:{business_presets:presets,profile:{type:'cafe'},biz:'Test'},state:{profile:{type:'cafe',promotion_by_type:{cafe:{language:'kk',theme:'night',cta:'Custom CTA',goal:'loyalty'}}},catalog:[]}});
vm.runInContext(fs.readFileSync('public/business-ui.js','utf8'),context);
assert.equal(vm.runInContext('businessPromotion().language',context),'kk');
assert.equal(vm.runInContext('businessCta()',context),'Custom CTA');
assert.equal(vm.runInContext('businessContentTopics()[0][0]',context),'faq');
context.state.profile.type='edu';
assert.equal(vm.runInContext('businessPromotion().language',context),'ru');
assert.equal(vm.runInContext('businessPromotion().theme',context),'forest');
context.state.catalog=[{id:1,details:{business_type:'cafe'}},{id:2,details:{business_type:'edu'}},{id:3,details:{}}];
assert.equal(vm.runInContext('businessCatalog().map(x=>x.id).join(",")',context),'2,3');
for(const type of Object.keys(presets)){
    context.state.profile.type=type;
    assert.equal(vm.runInContext('businessContentTopics().length',context),7);
    assert(vm.runInContext('businessContentTopics().some(x=>x[1]===businessPreset().topics[0])',context));
}
const nodes={socialSource:{dataset:{demo:'1'},textContent:'DEMO'},socialCatalog:{value:'1'},social_headline:{value:'Existing demo'},social_detail:{value:''},social_price:{value:''}};
context.$=id=>nodes[id];context.toast=()=>{};context.money=x=>String(x);context.socialBuild=()=>{};
vm.runInContext(fs.readFileSync('public/social-ui.js','utf8'),context);
context.state.profile.type='edu';context.state.catalog=[{id:1,item_name:'Unavailable',details:{business_type:'edu',availability:'out_of_stock'}}];
vm.runInContext('socialUseCatalog(1)',context);
assert.equal(nodes.socialSource.dataset.demo,'1');assert.equal(nodes.social_headline.value,'Existing demo');
vm.runInContext('socialUseCatalog(999)',context);assert.equal(nodes.socialSource.dataset.demo,'1');
console.log('PASS: 28 industry topic sets, type-specific defaults, catalogue isolation and unavailable-item draft preservation.');
