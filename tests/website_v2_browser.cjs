/* Run against a local static server. Requires externally installed Playwright.
   BASE_URL=http://127.0.0.1:8765 CHROME_BIN=/path/to/chromium node tests/website_v2_browser.cjs
   Inquiry responses are mocked; no Brevo request or email is sent. */
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const base=process.env.BASE_URL || 'http://127.0.0.1:8765';
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROME_BIN?{executablePath:process.env.CHROME_BIN}:{}),args:['--no-sandbox','--disable-gpu','--disable-dev-shm-usage']});
 try {
  for(const width of [390,768,1440]){
   const p=await browser.newPage({viewport:{width,height:900}});
   for(const file of ['index','contact','services','plans','pricing-estimator']){
    await p.goto(base+'/'+file+'.html');
    assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,file+' fits '+width);
   }
   if(width===390){await p.locator('#navToggle').click();assert.equal(await p.locator('#navToggle').getAttribute('aria-expanded'),'true');}
   await p.close();
  }
  console.log('PASS: responsive pages and mobile navigation');
  const p=await browser.newPage({viewport:{width:1440,height:1000}});
  let mode='error',posts=0,gets=0;
  await p.route('**/inquiry.php',r=>{
   if(r.request().method()==='GET'){gets++;return r.fulfill({json:{ok:true,token:'test-token',request_id:'test-reference'}});}
   posts++;
   return r.fulfill({status:mode==='expired'?403:mode==='error'?503:200,json:mode==='success'?{ok:true,reference:'test-reference'}:{ok:false,message:'Delivery unavailable. Your text is retained.'}});
  });
  await p.goto(base+'/contact.html');
  for(const [name,value] of Object.entries({name:'Test Owner',company:'Test Business',email:'test@example.com',message:'We need help planning our business IT.'}))await p.locator('[name='+name+']').fill(value);
  await p.locator('#inquirySubmit').click();
  await p.waitForFunction(()=>document.querySelector('#inquiryStatus').textContent.includes('Delivery unavailable'));
  assert.equal(await p.locator('[name=message]').inputValue(),'We need help planning our business IT.');
  assert.equal(await p.locator('#inquirySuccess').isVisible(),false);
  mode='expired';await p.locator('#inquirySubmit').click();
  await p.waitForFunction(()=>document.querySelector('#inquiryStatus').textContent.includes('session expired'));
  assert.equal(gets,2);assert.equal(await p.locator('[name=name]').inputValue(),'Test Owner');
  mode='success';await p.locator('#inquirySubmit').click();await p.locator('#inquirySuccess').waitFor({state:'visible'});
  assert.equal(posts,3);assert.equal(await p.locator('#inquiryForm').isVisible(),false);
  console.log('PASS: failed delivery and expired session retain input; successful retry displays reference');
  await p.goto(base+'/index.html');
  await p.evaluate(()=>sessionStorage.setItem('mmit-estimator-carry-v1',JSON.stringify({savedAt:Date.now(),params:{name:'Estimate Owner',company:'Estimate Company',email:'estimate@example.com',plan_fit:'Protect IT',estimate_range:'Test range',pain_points:'Test planning context'}})));
  await p.goto(base+'/contact.html');
  assert.equal(await p.locator('[name=name]').inputValue(),'Estimate Owner');assert.match(await p.locator('[name=message]').inputValue(),/Protect IT/);
  console.log('PASS: estimator context carried without query-string contact details');
  if(process.env.SCREENSHOTS){
   const fs=require('fs');fs.mkdirSync('docs/previews',{recursive:true});
   for(const [file,width] of [['index',1440],['contact',1440],['index',390]]){
    await p.setViewportSize({width,height:1000});await p.goto(base+'/'+file+'.html');
    await p.screenshot({path:'docs/previews/'+file+'-'+width+'.png',fullPage:true});
   }
  }
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
