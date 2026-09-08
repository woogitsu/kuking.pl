import {chromium} from 'playwright';
import {spawn,execFileSync} from 'node:child_process';
import {writeFileSync} from 'node:fs';
const db=c=>execFileSync('php',['artisan','tinker','--execute',c],{encoding:'utf8'}).trim();
const server=spawn('php',['artisan','serve','--host=127.0.0.1','--port=8124'],{stdio:'ignore'});
let browser;const out=[];
try {
 for(let i=0;i<60;i++){try{if((await fetch('http://127.0.0.1:8124/health')).ok)break;}catch{} await new Promise(r=>setTimeout(r,250));}
 const data=JSON.parse(db("$e=App\\Models\\CookedEvent::where('note','AUDYT ugotowalem bez JavaScriptu')->firstOrFail(); echo json_encode(['id'=>$e->id,'email'=>$e->recipe->author->email,'cook'=>$e->user_id]);"));
 browser=await chromium.launch({headless:true});const c=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:844}});const p=await c.newPage();
 await p.goto('http://127.0.0.1:8124/login');await p.locator('[name=login]').fill(data.email);await p.locator('[name=password]').fill('haslo-testowe-123');await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Zaloguj się',exact:true}).click()]);
 await p.goto('http://127.0.0.1:8124/powiadomienia');
 const a=p.locator(`a[href*="${data.id}"]`).first();out.push({step:'notification',target:await a.getAttribute('href')});
 await Promise.all([p.waitForNavigation(),a.click()]);await p.screenshot({path:'audit-evidence/browser/12-author-celebration.png',fullPage:true});out.push({step:'celebration',url:p.url(),text:await p.locator('main').innerText()});
 await p.locator('[name=body]').fill('AUDYT dziekuje za ugotowanie');await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'Podziękuj',exact:true}).click()]);
 out.push({step:'thank',url:p.url(),result:JSON.parse(db("$e=App\\Models\\CookedEvent::where('note','AUDYT ugotowalem bez JavaScriptu')->firstOrFail(); echo json_encode(['comment'=>App\\Models\\Comment::where('body','AUDYT dziekuje za ugotowanie')->count(),'cook_notifications'=>App\\Models\\Notification::where('user_id',$e->user_id)->where('data->cooked_event_id',$e->id)->count()]);"))});
} catch(e){out.push({error:String(e),stack:e.stack});process.exitCode=1;} finally{writeFileSync('audit-evidence/browser/author-loop.json',JSON.stringify(out,null,2));if(browser)await browser.close();server.kill();}
