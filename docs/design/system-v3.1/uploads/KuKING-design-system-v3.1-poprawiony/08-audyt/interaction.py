#!/usr/bin/env python3
import base64,json,mimetypes,sys,os
from pathlib import Path
from bs4 import BeautifulSoup
from playwright.sync_api import sync_playwright
ROOT=Path(sys.argv[1] if len(sys.argv)>1 else Path(__file__).resolve().parents[1]); OUT=Path(sys.argv[2] if len(sys.argv)>2 else '/tmp/kuking-interaction');OUT.mkdir(parents=True,exist_ok=True)
def html(p):
 s=BeautifulSoup(p.read_text(),'html.parser')
 for el in s.select('link[rel="stylesheet"]'):
  f=(p.parent/el['href']).resolve()
  if f.exists():
   tag=s.new_tag('style');tag.string=f.read_text();el.replace_with(tag)
 for el in s.select('img[src]'):
  f=(p.parent/el['src']).resolve()
  if f.exists():el['src']='data:'+mimetypes.guess_type(f)[0]+';base64,'+base64.b64encode(f.read_bytes()).decode()
 return str(s)
results=[]
with sync_playwright() as pw:
 browser=pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH','/usr/bin/chromium'),args=['--no-sandbox'])
 page=browser.new_page(viewport={'width':1440,'height':900},reduced_motion='reduce')
 for p in sorted(list((ROOT/'03-szablony').glob('*.html'))+list((ROOT/'04-strona-www').glob('*.html'))):
  print('Interactive',p.name,flush=True);page.set_content(html(p),wait_until='load')
  switch=page.get_by_role('switch',name='Ciemny wygl\u0105d');label=page.locator('.motyw-przelacznik')
  bg=lambda:page.locator('body').evaluate('(e)=>getComputedStyle(e).backgroundColor')
  initial=bg();label.click();page.wait_for_timeout(60);dark=bg();on=switch.is_checked();switch.focus();page.keyboard.press('Space');page.wait_for_timeout(60);off=not switch.is_checked();back=bg()
  result={'file':str(p.relative_to(ROOT)),'light_bg':initial,'dark_bg':dark,'click_changes_theme':on and dark!=initial,'space_restores_theme':off and back==initial,'alignment':[]}
  for width in [320,390,768,1024,1440,1920]:
   page.set_viewport_size({'width':width,'height':900})
   for scale in [1,2]:
    page.evaluate('(s)=>document.documentElement.style.setProperty("--user-text-scale",s)',str(scale))
    page.wait_for_timeout(25);page.evaluate('window.scrollTo(0,document.documentElement.scrollHeight)');page.wait_for_timeout(25)
    result['alignment'].append(page.evaluate('''() => {
     let c=document.querySelector('.motyw-przelacznik').getBoundingClientRect();
     let h=[...document.querySelectorAll('.topbar-akcje > :last-child,.pas-gorny-akcje > :last-child')].find(e=>e.getClientRects().length);
     let n=document.querySelector('.bottom-nav'); n=n&&n.getClientRects().length?n.getBoundingClientRect():null;
     return {width:innerWidth,scale:getComputedStyle(document.documentElement).getPropertyValue('--user-text-scale'),right:c.right,header_right:h?.getBoundingClientRect().right,control_width:c.width,control_height:c.height,control_bottom:c.bottom,nav_top:n?.top,above_mobile_nav:!n||c.bottom<=n.top};
    }'''))
  page.set_viewport_size({'width':1440,'height':900});page.evaluate('document.documentElement.style.setProperty("--user-text-scale","1")')
  page.emulate_media(forced_colors='active');switch.focus()
  result['forced_colors_focus']=label.evaluate('(e)=>({style:getComputedStyle(e).outlineStyle,width:getComputedStyle(e).outlineWidth,color:getComputedStyle(e).outlineColor})')
  page.emulate_media(forced_colors='none');results.append(result)
  (OUT/'interaction.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
 # Actual product screenshots; use switch, not injected data-theme.
 p=ROOT/'03-szablony/tablica.html';page.set_content(html(p),wait_until='load')
 for dark in [False,True]:
  if dark:page.locator('.motyw-przelacznik').click()
  theme='ciemny' if dark else 'jasny'
  for width in [1440,390]:
   page.set_viewport_size({'width':width,'height':900});page.evaluate('window.scrollTo(0,0)');page.wait_for_timeout(50)
   page.screenshot(path=str(OUT/f'kuking-start-{theme}-{width}.png'))
   page.locator('.motyw-przelacznik').scroll_into_view_if_needed();page.evaluate('window.scrollTo(0,document.documentElement.scrollHeight)');page.wait_for_timeout(50)
   page.screenshot(path=str(OUT/f'kuking-stopka-{theme}-{width}.png'))
   if width==1440:
    page.locator('.stopka').screenshot(path=str(OUT/f'kuking-stopka-crop-{theme}.png'))
    page.evaluate('window.scrollTo(0,0)');page.locator('.topbar').screenshot(path=str(OUT/f'kuking-naglowek-crop-{theme}.png'))
 browser.close()
print('TESTS',len(results),'pass click',sum(r['click_changes_theme'] for r in results),'pass keyboard',sum(r['space_restores_theme'] for r in results),'occluded',sum(not x['above_mobile_nav'] for r in results for x in r['alignment']))

failed = any(not r['click_changes_theme'] or not r['space_restores_theme'] or r['forced_colors_focus']['width']!='3px' or any(not x['above_mobile_nav'] or x['header_right'] is not None and abs(x['right']-x['header_right'])>1 for x in r['alignment']) for r in results)
raise SystemExit(1 if failed else 0)
