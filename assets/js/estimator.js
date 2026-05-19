(()=>{
const form=document.getElementById('pricingBuilder');
const result=document.getElementById('estimateResult');
if(!form||!result)return;

const laneInput=document.getElementById('estimateLane');
const workstationsInput=document.getElementById('estimateWorkstations');
const serversInput=document.getElementById('estimateServers');
const usersInput=document.getElementById('estimateUsers');
const addonInputs=Array.from(document.querySelectorAll('[data-addon-key]'));
const quotedInputs=Array.from(document.querySelectorAll('[data-quoted-note]'));
const carryButton=document.getElementById('carryEstimateForward');
const handoffForm=document.getElementById('estimateHandoffForm');
const handoffName=document.getElementById('estimateLeadName');
const handoffCompany=document.getElementById('estimateLeadCompany');
const handoffEmail=document.getElementById('estimateLeadEmail');
const handoffPhone=document.getElementById('estimateLeadPhone');
const handoffNote=document.getElementById('estimateLeadNote');
const estimateRequestStatus=document.getElementById('estimateRequestStatus');
const includedList=document.getElementById('estimateIncludedList');
const quotedList=document.getElementById('estimateQuotedList');
const breakdownList=document.getElementById('estimateBreakdown');
const estimateNumber=document.getElementById('estimateNumber');
const estimateSubtotal=document.getElementById('estimateSubtotal');
const estimateLaneSummary=document.getElementById('estimateLaneSummary');
const estimateDisclaimer=document.getElementById('estimateDisclaimer');
const estimateContext=document.getElementById('estimateContext');
const emptyState=document.getElementById('estimateEmpty');
const laneBadge=document.getElementById('estimateLaneBadge');
const addonCards=Array.from(document.querySelectorAll('.estimator-check[data-addon-lanes]'));
const pricingEndpoint='assets/data/pricing-config.json';
let config=null;
let lastEstimate=null;
const carryStorageKey='mmit-estimator-carry-v1';
const carryStorageTtlMs=1000*60*60*4;

function getSessionItem(key){
  try{return window.sessionStorage.getItem(key);}catch(error){return null;}
}

function setSessionItem(key,value){
  try{window.sessionStorage.setItem(key,value);}catch(error){}
}

function removeSessionItem(key){
  try{window.sessionStorage.removeItem(key);}catch(error){}
}

function phoneDigits(value){
  return String(value||'').replace(/\D+/g,'');
}

function formatPhoneForDisplay(value){
  const original=String(value||'').trim();
  let digits=phoneDigits(original);
  if(!digits)return'';
  if(digits.length===11&&digits.startsWith('1'))digits=digits.slice(1);
  if(digits.length>10)return original;
  if(digits.length<=3)return digits;
  if(digits.length<=6)return`(${digits.slice(0,3)}) ${digits.slice(3)}`;
  return`(${digits.slice(0,3)}) ${digits.slice(3,6)}-${digits.slice(6,10)}`;
}

function applyPhoneFormat(input){
  if(!input)return;
  const formatted=formatPhoneForDisplay(input.value);
  if(formatted&&formatted!==input.value){
    input.value=formatted;
  }
}

function bindPhoneFormatter(input){
  if(!input)return;
  const handle=()=>applyPhoneFormat(input);
  input.addEventListener('input',handle);
  input.addEventListener('change',handle);
  input.addEventListener('blur',handle);
  applyPhoneFormat(input);
}

function paramsToObject(params){
  const data={};
  params.forEach((value,key)=>{if(value)data[key]=value;});
  return data;
}

function readCarrySnapshot(){
  const raw=getSessionItem(carryStorageKey);
  if(!raw)return null;
  try{
    const snapshot=JSON.parse(raw);
    if(!snapshot||!snapshot.savedAt||Date.now()-Number(snapshot.savedAt)>carryStorageTtlMs){
      removeSessionItem(carryStorageKey);
      return null;
    }
    return snapshot;
  }catch(error){
    removeSessionItem(carryStorageKey);
    return null;
  }
}

function saveCarrySnapshot(estimate,lead=collectLead()){
  if(!estimate)return;
  const params=buildCarryParams(estimate,lead);
  setSessionItem(carryStorageKey,JSON.stringify({
    version:1,
    savedAt:Date.now(),
    source:'pricing-estimator',
    params:paramsToObject(params),
    estimate:{
      laneLabel:estimate.laneLabel,
      rangeText:estimate.rangeText,
      summaryText:estimate.summaryText,
      subtotal:estimate.subtotal,
      values:estimate.values,
      addonLabels:estimate.addonLabels,
      quotedItems:estimate.quotedItems
    }
  }));
}

function restoreLeadFromCarrySnapshot(){
  const snapshot=readCarrySnapshot();
  const params=snapshot&&snapshot.params?snapshot.params:{};
  if(params.name&&handoffName&&!handoffName.value)handoffName.value=params.name;
  if(params.company&&handoffCompany&&!handoffCompany.value)handoffCompany.value=params.company;
  if(params.email&&handoffEmail&&!handoffEmail.value)handoffEmail.value=params.email;
  if(params.phone&&handoffPhone&&!handoffPhone.value)handoffPhone.value=formatPhoneForDisplay(params.phone);
  if(params.pain_points&&handoffNote&&!handoffNote.value){
    const noteMatch=String(params.pain_points).match(/Estimator note:\s*(.+)$/);
    if(noteMatch&&noteMatch[1])handoffNote.value=noteMatch[1].trim();
  }
}

function money(value){
  return new Intl.NumberFormat('en-US',{style:'currency',currency:'USD',maximumFractionDigits:0}).format(value);
}

function bucketizeUsers(count){
  if(count<=5)return'1 to 5 users';
  if(count<=15)return'6 to 15 users';
  if(count<=30)return'16 to 30 users';
  if(count<=75)return'31 to 75 users';
  return'76+ users';
}

function setEstimateRequestStatus(message,type){
  if(!estimateRequestStatus)return;
  estimateRequestStatus.textContent=message;
  estimateRequestStatus.className='form-status ' + (type==='success' ? 'is-success' : 'is-error');
}

function syncAddonVisibility(){
  const lane=laneInput.value||'manage';
  addonCards.forEach((card)=>{
    const lanes=(card.dataset.addonLanes||'').split(',').map((value)=>value.trim()).filter(Boolean);
    const shouldShow=!lanes.length||lanes.includes(lane);
    card.style.display=shouldShow?'':'none';
    const checkbox=card.querySelector('input[type="checkbox"]');
    if(!shouldShow&&checkbox)checkbox.checked=false;
  });
  const addonSection=document.getElementById('estimatorAddons');
  if(addonSection){
    addonSection.style.display=addonCards.some((card)=>card.style.display!=='none')?'':'none';
  }
}

function collect(){
  return{
    lane:laneInput.value||'manage',
    workstations:Math.max(Number(workstationsInput.value||0),0),
    servers:Math.max(Number(serversInput.value||0),0),
    users:Math.max(Number(usersInput.value||0),0),
    addons:addonInputs.filter((input)=>input.checked).map((input)=>input.dataset.addonKey),
    quoted:quotedInputs.filter((input)=>input.checked).map((input)=>input.dataset.quotedNote)
  };
}

function collectLead(){
  return {
    name:handoffName?handoffName.value.trim():'',
    company:handoffCompany?handoffCompany.value.trim():'',
    email:handoffEmail?handoffEmail.value.trim():'',
    phone:handoffPhone?formatPhoneForDisplay(handoffPhone.value):'',
    note:handoffNote?handoffNote.value.trim():'',
  };
}

function leadBusinessProfile(values){
  if(values.servers>0)return'Hybrid or multi-location team';
  if(values.users>=10)return'Microsoft 365 cleanup needed';
  return'Growing small business';
}

function leadInterest(values){
  if(values.lane==='manage')return'Managed IT Services';
  if(values.lane==='protect')return'Cybersecurity';
  return'Microsoft 365 & Cloud';
}

function buildCarryParams(estimate, lead = collectLead()){
  const painParts=[estimate.summaryText];
  if(lead.note)painParts.push(`Estimator note: ${lead.note}`);
  const params=new URLSearchParams({
    plan_fit:estimate.laneLabel,
    team_size:bucketizeUsers(Math.max(estimate.values.workstations,estimate.values.users||0)),
    interest:leadInterest(estimate.values),
    business_profile:leadBusinessProfile(estimate.values),
    contact_method:'Video meeting',
    estimate_range:estimate.rangeText,
    availability:`Estimator planning range: ${estimate.rangeText}`,
    pain_points:painParts.join(' | ')
  });
  if(lead.name)params.set('name',lead.name);
  if(lead.company)params.set('company',lead.company);
  if(lead.email)params.set('email',lead.email);
  if(lead.phone)params.set('phone',lead.phone);
  return params;
}

function updateCarryLink(estimate){
  if(!carryButton||!estimate){
    if(carryButton)carryButton.href='contact.html#schedule-intake';
    return;
  }
  const lead=collectLead();
  const params=buildCarryParams(estimate,lead);
  saveCarrySnapshot(estimate,lead);
  carryButton.href=`contact.html?${params.toString()}#schedule-intake`;
}

function render(){
  if(!config)return;
  const values=collect();
  const lane=config.lanes[values.lane];

  if(!lane||(values.workstations<=0&&values.servers<=0)){
    emptyState.style.display='';
    estimateNumber.textContent='$0';
    estimateSubtotal.textContent='Add at least one managed workstation or server to build an estimate.';
    includedList.innerHTML='';
    breakdownList.innerHTML='';
    quotedList.innerHTML='';
    laneBadge.textContent='Planning estimate';
    estimateLaneSummary.textContent='Use the inputs on the left to see a transparent planning range built around the lane you selected.';
    estimateContext.textContent='The result here is a planning number, not a contract or a final quote.';
    estimateDisclaimer.textContent='Special licensing mixes, server backup, project work, and environment complexity are reviewed in the follow-up conversation.';
    lastEstimate=null;
    updateCarryLink(null);
    return;
  }

  emptyState.style.display='none';
  const breakdown=[];
  let subtotal=0;

  if(values.workstations>0){
    const workstationTotal=values.workstations*lane.workstation_rate;
    subtotal+=workstationTotal;
    breakdown.push([`${lane.label} × ${values.workstations} workstation${values.workstations===1?'':'s'}`,workstationTotal]);
  }

  if(values.servers>0){
    const serverRate=Number(lane.server_rate||0);
    if(serverRate>0){
      const serverTotal=values.servers*serverRate;
      subtotal+=serverTotal;
      breakdown.push([`${lane.label} × ${values.servers} server${values.servers===1?'':'s'}`,serverTotal]);
    }
  }

  values.addons.forEach((key)=>{
    const addon=config.addons[key];
    if(!addon)return;
    let qty=0;
    if(addon.basis==='server')qty=values.servers;
    if(addon.basis==='user'||addon.basis==='cloud_user')qty=values.users;
    if(addon.basis==='workstation')qty=values.workstations;
    if(addon.basis==='managed_device'||addon.basis==='device')qty=values.workstations+values.servers;
    if(addon.basis==='flat')qty=1;
    if(qty<=0)return;
    const lineTotal=qty*addon.rate;
    subtotal+=lineTotal;
    const basisLabel=addon.basis==='managed_device'?'managed device':addon.basis;
    breakdown.push([`${addon.label} × ${qty} ${basisLabel}${qty===1?'':'s'}`,lineTotal]);
  });

  const complexityPad=
    lane.high_pad+
    (values.servers>0?0.03:0)+
    (values.workstations>=25?0.04:values.workstations>=10?0.02:0)+
    (values.addons.length>=2?0.02:0);

  const low=Math.round(subtotal/5)*5;
  const high=Math.ceil((subtotal*(1+complexityPad))/5)*5;
  const rangeText=`${money(low)} to ${money(high)}/mo`;

  laneBadge.textContent=lane.label;
  estimateNumber.textContent=`${money(low)} to ${money(high)}`;
  estimateSubtotal.textContent=`Planning subtotal: ${money(subtotal)}/mo before complexity or licensing-mix adjustments.`;
  estimateLaneSummary.textContent=lane.summary;

  const rateParts=[`${money(lane.workstation_rate)} per managed workstation`];
  if(Number(lane.server_rate||0)>0){
    rateParts.push(`${money(lane.server_rate)} per managed server`);
  }
  estimateContext.textContent=`This estimate uses ${rateParts.join(' and ')} for ${lane.label} and layers in the options selected below.`;

  estimateDisclaimer.textContent='This planner is meant to improve transparency, not to create a binding quote. Final pricing can change based on onboarding needs, licensing mix, compliance scope, server backup, and environment complexity.';

  includedList.innerHTML=lane.included.map((item)=>`<li><span>${item}</span></li>`).join('');
  breakdownList.innerHTML=breakdown.map(([label,amount])=>`<li><span>${label}</span><span>${money(amount)}/mo</span></li>`).join('');

  const quotedItems=[...config.quoted_separately];
  values.quoted.forEach((note)=>{
    if(!quotedItems.includes(note))quotedItems.push(note);
  });
  quotedList.innerHTML=quotedItems.map((item)=>`<li><span>${item}</span></li>`).join('');

  const addonLabels=values.addons.map((key)=>config.addons[key]?.label).filter(Boolean);
  const summaryText=[
    `Estimator summary: ${lane.label}`,
    values.workstations?`${values.workstations} managed workstation${values.workstations===1?'':'s'}`:null,
    values.servers?`${values.servers} managed server${values.servers===1?'':'s'}`:null,
    values.users?`${values.users} cloud user${values.users===1?'':'s'}`:null,
    addonLabels.length?`Add-ons: ${addonLabels.join(', ')}`:'Add-ons: none selected',
    `Estimated monthly planning range: ${rangeText}`
  ].filter(Boolean).join(' | ');

  lastEstimate={
    values,
    laneLabel:lane.label,
    rangeText,
    summaryText,
    subtotal,
    breakdown,
    quotedItems,
    addonLabels
  };
  updateCarryLink(lastEstimate);
}

function validateLeadFields(){
  const lead=collectLead();
  if(!lead.name){
    setEstimateRequestStatus('Please enter your name so we can carry the estimate forward cleanly.', 'error');
    handoffName&&handoffName.focus();
    return false;
  }
  if(!lead.company){
    setEstimateRequestStatus('Please enter your company name before choosing the next step.', 'error');
    handoffCompany&&handoffCompany.focus();
    return false;
  }
  if(!lead.email||!handoffEmail||!handoffEmail.checkValidity()){
    setEstimateRequestStatus('Please enter a valid email address.', 'error');
    handoffEmail&&handoffEmail.focus();
    return false;
  }
  return true;
}

if(carryButton){
  carryButton.addEventListener('click',(event)=>{
    if(!lastEstimate){
      event.preventDefault();
      setEstimateRequestStatus('Build an estimate first, then carry it forward.', 'error');
      return;
    }
    if(!validateLeadFields()){
      event.preventDefault();
      return;
    }
    setEstimateRequestStatus('', 'success');
    saveCarrySnapshot(lastEstimate);
    updateCarryLink(lastEstimate);
  });
}

bindPhoneFormatter(handoffPhone);

addonInputs.forEach((input)=>{
  input.addEventListener('change',()=>{
    if(!input.checked)return;
    const group=input.dataset.addonGroup;
    if(!group)return;
    addonInputs.forEach((peer)=>{
      if(peer!==input&&peer.dataset.addonGroup===group){
        peer.checked=false;
      }
    });
    render();
  });
});

if(handoffForm){
  handoffForm.addEventListener('input',()=>{
    if(lastEstimate){
      saveCarrySnapshot(lastEstimate);
      updateCarryLink(lastEstimate);
    }
  });

  handoffForm.addEventListener('submit', async (event)=>{
    event.preventDefault();
    if(!lastEstimate){
      setEstimateRequestStatus('Build an estimate first, then choose the email path.', 'error');
      return;
    }
    if(!validateLeadFields())return;

    const endpoint=handoffForm.dataset.estimateEndpoint||'estimate-request.php';
    const lead=collectLead();
    saveCarrySnapshot(lastEstimate,lead);
    const payload={
      name:lead.name,
      company:lead.company,
      email:lead.email,
      phone:lead.phone,
      note:lead.note,
      lane:lastEstimate.laneLabel,
      lane_key:lastEstimate.values.lane,
      workstations:lastEstimate.values.workstations,
      servers:lastEstimate.values.servers,
      cloud_users:lastEstimate.values.users,
      users:lastEstimate.values.users,
      addons:lastEstimate.addonLabels,
      addon_keys:lastEstimate.values.addons,
      quoted_items:lastEstimate.quotedItems,
      estimate_range:lastEstimate.rangeText,
      summary:lastEstimate.summaryText,
      estimate_summary:lastEstimate.summaryText,
      next_step:'EMAIL_ESTIMATE',
      pain_points:lead.note || lastEstimate.summaryText,
      source:'pricing-estimator'
    };

    try{
      const response=await fetch(endpoint,{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify(payload)
      });
      const data=await response.json().catch(()=>({}));
      if(!response.ok){
        throw new Error(data.message||'Unable to send the estimate right now.');
      }
      const finalEstimate={
        ...lastEstimate,
        laneLabel:data.estimate_lane||lastEstimate.laneLabel,
        rangeText:data.estimate_range||lastEstimate.rangeText,
        summaryText:data.estimate_summary||lastEstimate.summaryText
      };
      setEstimateRequestStatus(data.message||'Thanks. We received your estimate request and someone will follow up by email.', 'success');
      saveCarrySnapshot(finalEstimate,lead);
      const params=buildCarryParams(finalEstimate, lead);
      params.set('mode','estimate-email');
      params.set('company',lead.company);
      params.set('range',finalEstimate.rangeText);
      params.set('lane',finalEstimate.laneLabel);
      window.setTimeout(()=>{
        window.location.href=`thanks.html?${params.toString()}`;
      }, 550);
    }catch(error){
      setEstimateRequestStatus(error.message||'Unable to send the estimate right now. Please try again.', 'error');
    }
  });
}

fetch(pricingEndpoint)
  .then((response)=>response.json())
  .then((data)=>{
    config=data;
    restoreLeadFromCarrySnapshot();
    syncAddonVisibility();
    render();
    form.addEventListener('input',()=>{
      syncAddonVisibility();
      render();
    });
    form.addEventListener('change',()=>{
      syncAddonVisibility();
      render();
    });
  })
  .catch(()=>{
    estimateNumber.textContent='Unavailable';
    estimateSubtotal.textContent='Pricing data could not be loaded on this page.';
    estimateDisclaimer.textContent='The planner is temporarily unavailable. Please schedule a chat for a manual estimate.';
    lastEstimate=null;
    updateCarryLink(null);
  });
})();
