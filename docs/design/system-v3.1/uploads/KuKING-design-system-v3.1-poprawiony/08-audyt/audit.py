#!/usr/bin/env python3
import json,sys,collections,base64,mimetypes,os
from pathlib import Path
from urllib.parse import urlsplit,unquote
from bs4 import BeautifulSoup
from playwright.sync_api import sync_playwright
ROOT=Path(sys.argv[1] if len(sys.argv)>1 else str(Path(__file__).resolve().parents[1]))
OUT=Path(sys.argv[2] if len(sys.argv)>2 else '/tmp/kuking-audit'); OUT.mkdir(parents=True,exist_ok=True)
files=sorted(p for p in ROOT.rglob('*.html') if p.name not in ['ikony-sprite.html','urzadzenia.html'] and 'node_modules' not in p.parts)
static=[]
for p in files:
 soup=BeautifulSoup(p.read_text(),'html.parser'); ids=collections.Counter(x['id'] for x in soup.select('[id]'))
 issues={'file':str(p.relative_to(ROOT)),'duplicates':[x for x,n in ids.items() if n>1], 'missing':[], 'placeholder_links':len(soup.select('a[href="#"]')), 'routes':[], 'forms':[]}
 for x in soup.select('[href], img[src], iframe[src], link[href]'):
  url=x.get('href',x.get('src','')); u=urlsplit(url)
  if u.scheme or u.netloc: continue
  if u.path.startswith('/'): issues['routes'].append(url);continue
  target=p.parent/unquote(u.path) if u.path else p
  if u.path and not target.exists(): issues['missing'].append(url)
  elif u.fragment and target.suffix in ['.html','']:
   target_soup=soup if target==p else BeautifulSoup(target.read_text(),'html.parser')
   if not target_soup.find(id=u.fragment): issues['missing'].append(url)
 for f in soup.select('form'):
  issues['forms'].append({'action':f.get('action'),'method':f.get('method','get'),'fields':len(f.select('input,textarea,select')),'buttons':[{'type':b.get('type'),'text':b.get_text(' ',strip=True),'href':b.get('href')} for b in f.select('button,a')]})
 static.append(issues)
(OUT/'static.json').write_text(json.dumps(static,ensure_ascii=False,indent=2))
resume=len(sys.argv)>3 and sys.argv[3]=='resume'
results=json.loads((OUT/'layout.json').read_text()) if resume and (OUT/'layout.json').exists() else []
contrast=json.loads((OUT/'contrast.json').read_text()) if resume and (OUT/'contrast.json').exists() else []
completed={r['file'] for r in results}
with sync_playwright() as pw:
 browser=pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH','/usr/bin/chromium'),args=['--no-sandbox'])
 page=browser.new_page(viewport={'width':1440,'height':900},reduced_motion='reduce')
 for p in files:
  rel=str(p.relative_to(ROOT))
  if rel in completed:continue
  print('Checking',rel,flush=True)
  soup=BeautifulSoup(p.read_text(),'html.parser')
  for link in soup.select('link[rel="stylesheet"]'):
   path=(p.parent/link['href']).resolve()
   if path.exists():
    tag=soup.new_tag('style');tag.string=path.read_text();link.replace_with(tag)
  for img in soup.select('img[src]'):
   path=(p.parent/img['src']).resolve()
   if path.exists():img['src']='data:'+mimetypes.guess_type(path)[0]+';base64,'+base64.b64encode(path.read_bytes()).decode()
  page.set_content(str(soup),wait_until='load'); page.wait_for_timeout(40)
  for theme in ['light','dark']:
   page.evaluate('(t)=>document.documentElement.dataset.theme=t',theme)
   for width in [320,390,768,1024,1279,1280,1440,1920]:
    page.set_viewport_size({'width':width,'height':900})
    for scale in [1,1.5,2]:
     page.evaluate('(s)=>document.documentElement.style.setProperty("--user-text-scale",s)',str(scale))
     page.wait_for_timeout(20)
     r=page.evaluate('''() => {
       const vis=e=>e.getClientRects().length>0 && getComputedStyle(e).visibility!=='hidden';
       const rect=e=>{let r=e.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}};
       let tooSmall=[...document.querySelectorAll('.btn,.chip,.side-nav-item,.bottom-nav-item,.field-input,.wybor-zdjecia')].filter(vis).map(e=>({text:e.textContent.trim().slice(0,50),tag:e.tagName,cls:e.className,...rect(e)})).filter(r=>r.height<47.5 || r.width<43.5);
       let overflow=document.documentElement.scrollWidth>document.documentElement.clientWidth+1?[...document.querySelectorAll('body *')].filter(e=>vis(e)&&!e.closest('svg')&&e.scrollWidth>e.clientWidth+3&&getComputedStyle(e).overflowX==='visible'&&e.clientWidth>0).slice(0,12).map(e=>({tag:e.tagName,cls:e.className,text:e.textContent.trim().slice(0,60),client:e.clientWidth,scroll:e.scrollWidth})):[];
       let geom={};for(let sel of ['.topbar','.topbar-wnetrze','.topbar-akcje','.app-main','.app-rail','.stopka-wnetrze','.stopka-wnetrze>form','.bottom-nav']){let e=document.querySelector(sel);if(e&&vis(e))geom[sel]=rect(e)};
       return {scroll:document.documentElement.scrollWidth,client:document.documentElement.clientWidth,tooSmall,overflow,geom};
     }''')
     r.update(file=rel,theme=theme,width=width,scale=scale);results.append(r)
   page.set_viewport_size({'width':1440,'height':900});page.evaluate('document.documentElement.style.setProperty("--user-text-scale","1")')
   c=page.evaluate(r'''() => {
    const rgb=s=>(s.match(/[\d.]+/g)||[]).map(Number);
    const lum=c=>c.slice(0,3).map(v=>{v/=255;return v<=0.04045?v/12.92:((v+0.055)/1.055)**2.4}).reduce((a,v,i)=>a+v*[0.2126,0.7152,0.0722][i],0);
    let out=[];
    for(let e of document.querySelectorAll('body *')){
     if(!e.getClientRects().length || e.closest('svg,button:disabled,[aria-hidden="true"],.tylko-dla-czytnika'))continue;
     const text=[...e.childNodes].filter(n=>n.nodeType===3).map(n=>n.textContent.trim()).join(' ').trim(); if(!text)continue;
     const s=getComputedStyle(e);if(s.visibility==='hidden')continue;
     let bg=null,anc=e;while(anc){let a=getComputedStyle(anc);let col=rgb(a.backgroundColor);if(a.backgroundImage!=='none'){bg=null;break;}if(col.length===3||col[3]===1){bg=col;break;}if(col[3]>0){bg=null;break;}anc=anc.parentElement;}
     if(!bg)continue;let fg=rgb(s.color);if(fg.length>3&&fg[3]!==1)continue;
     let ratio=(Math.max(lum(fg),lum(bg))+.05)/(Math.min(lum(fg),lum(bg))+.05);let size=parseFloat(s.fontSize),weight=parseFloat(s.fontWeight);let min=(size>=24 || size>=18.6667&&weight>=700)?3:4.5;
     if(ratio<min)out.push({text:text.slice(0,100),tag:e.tagName,cls:e.className,color:s.color,bg:bg.slice(0,3),ratio,size,weight,min});
    }return out;
   }''')
   contrast.append({'file':rel,'theme':theme,'failures':c})
  if False and rel in ['03-szablony/tablica.html','03-szablony/przepis.html','03-szablony/dodaj-przepis-krok-1.html','04-strona-www/powitalna.html']:
   for theme in ['light','dark']:
    page.evaluate('(t)=>document.documentElement.dataset.theme=t',theme)
    for width in [390,1440]:
     page.set_viewport_size({'width':width,'height':900});page.evaluate('window.scrollTo(0,0)')
     page.screenshot(path=str(OUT/(p.stem+'-'+theme+'-'+str(width)+'.png')),full_page=True)
  (OUT/'layout.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
  (OUT/'contrast.json').write_text(json.dumps(contrast,ensure_ascii=False,indent=2))
 browser.close()
(OUT/'layout.json').write_text(json.dumps(results,ensure_ascii=False,indent=2));(OUT/'contrast.json').write_text(json.dumps(contrast,ensure_ascii=False,indent=2))
summary={'pages':len(files),'measurements':len(results),'horizontal_overflow':[r for r in results if r['scroll']>r['client']+1],'small_controls':sum(bool(r['tooSmall']) for r in results),'contrast_failures':sum(len(r['failures']) for r in contrast),'missing_links':sum(len(r['missing']) for r in static),'duplicate_ids':sum(len(r['duplicates']) for r in static),'placeholder_links':sum(r['placeholder_links'] for r in static)}
(OUT/'summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2));print({k:(len(v) if isinstance(v,list) else v) for k,v in summary.items()},flush=True)

raise SystemExit(1 if any([summary['horizontal_overflow'], summary['small_controls'], summary['contrast_failures'], summary['missing_links'], summary['duplicate_ids']]) else 0)
