const {chromium}=require('playwright');const CleanCSS=require('clean-css');const fs=require('fs');
const cx=u=>require('child_process').execSync('curl -s --compressed '+JSON.stringify(u),{maxBuffer:5e7}).toString();
const pages=process.argv.slice(2);const files={};const order=[];const kept={};
const key=h=>h.split('?')[0];
(async()=>{const b=await chromium.launch();
for(const url of pages){
 const html=cx(url);
 const links=[...html.matchAll(/<link[^>]*rel='stylesheet'[^>]*href='([^']+)'[^>]*media='([^']+)'[^>]*>/g)];
 let h2=html,styles='';
 for(const [tag,h,m] of links){const k=key(h);if(!files[k]){let c=cx(h);c=c.replace(/url\((['"]?)(?!data:|https?:|\/\/)/g,(s,q)=>'url('+q+new URL('.',h).href);files[k]={css:c,media:m};kept[k]=new Set();
   const ie=order.findIndex(x=>/eg\.css$/.test(x)); if(/eg\.css$/.test(k)||ie<0)order.push(k); else order.splice(ie,0,k);}
  h2=h2.replace(tag,'');styles+=`<style data-k="${k}" media="${m}">${files[k].css.replace(/<\/style/g,'')}</style>`;}
 h2=h2.replace('</head>',styles+'</head>');
 for(const [w,hh] of [[412,823],[1366,900]]){
  const ctx=await b.newContext({viewport:{width:w,height:hh}});const p=await ctx.newPage();
  await p.route(url,r=>r.fulfill({body:h2,contentType:'text/html; charset=utf-8'}));
  await p.goto(url,{waitUntil:'load'});await p.waitForTimeout(500);
  const res=await p.evaluate((H)=>{
   const vis=sel=>{let s=sel.replace(/::?(before|after|placeholder|marker|selection|backdrop|-webkit-[a-z-]+|-moz-[a-z-]+)(\([^)]*\))?/g,'').replace(/:(hover|focus|focus-visible|focus-within|active|visited|target)/g,'');if(!s.trim())s='*';let els;try{els=document.querySelectorAll(s)}catch(e){return false}
    for(let e of els){let rc=e.getBoundingClientRect();while(rc.width==0&&rc.height==0&&e.parentElement){e=e.parentElement;rc=e.getBoundingClientRect();}if(rc.top<H*1.3&&rc.bottom>=-5)return true;}return false};
   const out={};
   for(const st of document.querySelectorAll('style[data-k]')){if(!matchMedia(st.media).matches)continue;const ks=[];
    const walk=(rules,pre)=>{[...rules].forEach((r,i)=>{const path=pre+i;
     if(r instanceof CSSStyleRule){if(/^(:root|html|body|\*|\*,[^{]*)$/.test(r.selectorText)||r.selectorText.split(',').some(vis))ks.push(path);}
     else if(r instanceof CSSMediaRule){if(matchMedia(r.conditionText).matches)walk(r.cssRules,path+'.');}
     else if(r instanceof CSSFontFaceRule||(r.cssText&&/^@property/.test(r.cssText)))ks.push(path);
     else if(r instanceof CSSKeyframesRule){}
     else if(r.cssRules)walk(r.cssRules,path+'.');});};
    walk(st.sheet.cssRules,'');out[st.dataset.k]=ks;}
   return out},hh);
  for(const k in res)res[k].forEach(x=>kept[k].add(x));await ctx.close();}}
// emit
const p=await (await b.newContext()).newPage();
await p.setContent('<html><head>'+order.map(k=>`<style data-k="${k}">${files[k].css.replace(/<\/style/g,'')}</style>`).join('')+'</head><body></body></html>');
const parts=await p.evaluate((kept)=>{const res=[];for(const st of document.querySelectorAll('style[data-k]')){const K=new Set(kept[st.dataset.k]);
  const emit=(rules,pre)=>{let o='';[...rules].forEach((r,i)=>{const path=pre+i;if(K.has(path))o+=r.cssText;else if(r.cssRules&&!(r instanceof CSSStyleRule)&&!(r instanceof CSSKeyframesRule)){const inner=emit(r.cssRules,path+'.');if(inner)o+=r.cssText.slice(0,r.cssText.indexOf('{'))+'{'+inner+'}';}});return o};
  res.push([st.dataset.k,emit(st.sheet.cssRules,'')]);}return res},Object.fromEntries(Object.entries(kept).map(([k,v])=>[k,[...v]])));
let all='';for(const [k,c] of parts){if(!c)continue;const m=files[k].media;all+=(m==='all'?c:`@media ${m}{${c}}`)+'\n';}
const min=new CleanCSS({level:1}).minify(all).styles;fs.writeFileSync('critical.css',min);console.error('total',min.length,order.length);await b.close();})().catch(e=>{console.error(e);process.exit(1)});
