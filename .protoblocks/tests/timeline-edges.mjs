import { chromium } from 'playwright';
const URL='https://cadco.local/about/';
const out={};
const b=await chromium.launch();

// 1. reduced motion: no pin, no hijacked scroll, content reachable
{
  const p=await b.newPage({viewport:{width:1440,height:900},ignoreHTTPSErrors:true,reducedMotion:'reduce'});
  const errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,100)));
  await p.goto(URL,{waitUntil:'load'}); await p.waitForTimeout(5000);
  out.reducedMotion=await p.evaluate(()=>{ const s=document.querySelector('#pb-s4');
    return { pinned:!!s.closest('.pin-spacer'),
             triggers:(window.ScrollTrigger?window.ScrollTrigger.getAll().filter(t=>t.trigger===s).length:0),
             scrollable:getComputedStyle(s.querySelector('[data-timeline-track]').parentElement).overflowX }; });
  out.reducedMotion.errors=errs.length?errs:null;
  await p.close();
}

// 2. narrow viewports: the rail must still be reachable and not overflow the page
for (const [name,w,h] of [['tablet',768,1024],['mobile',390,844]]) {
  const p=await b.newPage({viewport:{width:w,height:h},ignoreHTTPSErrors:true});
  const errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,100)));
  await p.goto(URL,{waitUntil:'load'}); await p.waitForTimeout(5000);
  out[name]=await p.evaluate((vw)=>{ const s=document.querySelector('#pb-s4');
    return { pinned:!!s.closest('.pin-spacer'), docOverflow:document.documentElement.scrollWidth>vw,
             sectionH:Math.round(s.getBoundingClientRect().height) }; }, w);
  out[name].errors=errs.length?errs:null;
  await p.close();
}

// 3. arrows under the pin actually advance the page scroll
{
  const p=await b.newPage({viewport:{width:1440,height:900},ignoreHTTPSErrors:true});
  await p.goto(URL,{waitUntil:'load'}); await p.waitForTimeout(5000);
  await p.evaluate(()=>{ const st=window.ScrollTrigger.getAll().find(t=>t.trigger===document.querySelector('#pb-s4')); window.scrollTo(0,st.start+1); });
  await p.waitForTimeout(1200);
  const before=await p.evaluate(()=>Math.round(window.scrollY));
  await p.click('#pb-s4 [data-timeline-next]'); await p.waitForTimeout(1600);
  const after=await p.evaluate(()=>Math.round(window.scrollY));
  await p.click('#pb-s4 [data-timeline-prev]'); await p.waitForTimeout(1600);
  const back=await p.evaluate(()=>Math.round(window.scrollY));
  out.arrows={before,afterNext:after,advanced:after>before,afterPrev:back,returned:back<after};
  await p.close();
}

// 4. navigating away must leave no pin spacer behind
{
  const p=await b.newPage({viewport:{width:1440,height:900},ignoreHTTPSErrors:true});
  const errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,100)));
  await p.goto(URL,{waitUntil:'load'}); await p.waitForTimeout(5000);
  const link=await p.$('a[href*="/products"]');
  if(link){ await link.click(); await p.waitForTimeout(4500); }
  out.afterNavigate=await p.evaluate(()=>({ url:location.pathname,
    straySpacers:document.querySelectorAll('.pin-spacer').length,
    timelineTriggers:(window.ScrollTrigger?window.ScrollTrigger.getAll().filter(t=>t.vars&&t.vars.pin).length:0) }));
  out.afterNavigate.errors=errs.length?errs:null;
  await p.close();
}

await b.close();
console.log(JSON.stringify(out,null,1));
