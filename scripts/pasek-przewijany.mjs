import assert from 'node:assert/strict';
import {mkdirSync,writeFileSync} from 'node:fs';
import {wymagajStanu} from './lib/stan-ustalony.mjs';

/* STAN PASKA ZAMIAST ZEGARA (24 września 2026).
   Do tej pory każdy krok czekał stałe 300 ms i dopiero wtedy mierzył pasek.
   Chowanie ma dwa opóźnienia, żadne ze stałym czasem: obsługa przewinięcia
   idzie w `requestAnimationFrame`, a samo przesunięcie to przejście
   `transform 180ms` (`pasek-przewijany.css`). Na zajętym runnerze 300 ms
   nie wystarczyło i kontrola padła na DOM-NEW-01 z „pasek powinien być poza
   ekranem (gość)”, a ponowienie gdzie indziej przeszło. Teraz: przewijamy,
   czekamy, aż skrypt strony OBSŁUŻY to przewinięcie (dwie klatki po
   zdarzeniu `scroll`), a potem aż pasek osiągnie oczekiwaną pozycję i nie
   będzie w nim trwającego przejścia. Limit czasu jest bezpiecznikiem;
   porażka podaje zmierzoną pozycję, atrybut, transform i przejścia. */
const LIMIT_STANU_MS = 10_000;

async function przewin(p, ruch) {
  await p.evaluate(ruch => new Promise(gotowe => {
    const przed = scrollY;
    // Nasz nasłuch rejestrujemy PO nasłuchu strony, więc jej klatka
    // obsługi przewinięcia wykona się przed naszą drugą klatką.
    const poObsludze = () => requestAnimationFrame(() => requestAnimationFrame(gotowe));
    addEventListener('scroll', poObsludze, {once: true});
    if ('do' in ruch) scrollTo(0, ruch.do); else scrollBy(0, ruch.o);
    // Bez zmiany pozycji nie będzie zdarzenia `scroll` — nie wisimy na nim.
    if (scrollY === przed) { removeEventListener('scroll', poObsludze); poObsludze(); }
  }), ruch);
}

function pomiarPaska(_, przejscia) {
  const bar = document.querySelector('[data-pasek-przewijany]');
  const box = bar.getBoundingClientRect();
  const styl = getComputedStyle(bar);
  return {scrollY, y: box.y, bottom: box.bottom, height: box.height, schowany: bar.hasAttribute('data-pasek-schowany'), transform: styl.transform, pozycja: styl.position, fokusWPasku: bar.contains(document.activeElement), przejscia: przejscia(bar)};
}

async function stanPaska(p, oczekiwany, kto, opis) {
  return wymagajStanu(p, {
    opis: `${opis} (${kto}): oczekiwano paska ${oczekiwany}`,
    arg: oczekiwany,
    limitMs: LIMIT_STANU_MS,
    warunek: (oczekiwany, przejscia) => {
      const bar = document.querySelector('[data-pasek-przewijany]');
      if (!bar || przejscia(bar).length > 0) return false;
      const box = bar.getBoundingClientRect();
      const schowany = bar.hasAttribute('data-pasek-schowany');
      if (oczekiwany === 'schowany') return schowany && box.bottom <= 0;
      if (oczekiwany === 'widoczny') return !schowany && box.y >= 0;
      return true; // 'ustalony': tylko koniec przejść
    },
    pomiar: pomiarPaska,
  });
}

/* Jeden przebieg macierzy na podanej stronie. Wydzielone z pętli, bo od
   19 września 2026 ta sama macierz idzie w DWÓCH stanach zalogowania: pasek
   chowa się teraz także po zalogowaniu (`layout.blade.php`), a do tamtej pory
   atrybut stał pod `@guest` i mierzyliśmy wyłącznie widok gościa. */
async function przebieg({p,bar,sticky,out,width,theme,scale,kto}) {
 // Bez `position: sticky` skrypt pasek zawsze pokazuje; czekamy tylko na koniec przejść.
 const poZjezdzie=sticky?'schowany':'widoczny';
 await przewin(p,{do:1000});
 if(sticky){const m=await stanPaska(p,'schowany',kto,'pasek powinien być poza ekranem');assert(m.bottom<=0,`pasek powinien być poza ekranem (${kto}) ${JSON.stringify(m)}`);}
 else await stanPaska(p,'widoczny',kto,'pasek bez sticky');
 await przewin(p,{o:-90});
 {const m=await stanPaska(p,'widoczny',kto,'powrót w górę');if(sticky)assert(m.y>=0,`powrót w górę (${kto}) ${JSON.stringify(m)}`);}
 await przewin(p,{o:100});
 await stanPaska(p,poZjezdzie,kto,'ponowny zjazd przed próbą fokusu');
 await bar.locator('a').first().focus();await p.keyboard.press('Tab');
 assert(await bar.evaluate(e=>e.contains(document.activeElement)));assert(!await bar.evaluate(e=>e.hasAttribute('data-pasek-schowany')));
 if(sticky){const m=await stanPaska(p,'widoczny',kto,'fokus widoczny');assert(m.y>=0,`fokus widoczny (${kto}) ${JSON.stringify(m)}`);}
 await p.evaluate(()=>document.activeElement.blur());await przewin(p,{do:0});
 {const m=await stanPaska(p,'widoczny',kto,'powrót na górę strony');assert(m.y>=0,`powrót na górę strony (${kto}) ${JSON.stringify(m)}`);}
 assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
 if(width===390&&scale===100&&theme==='light'&&kto==='gość'){
  await p.screenshot({path:out+'/widoczny.png'});await przewin(p,{do:1000});await stanPaska(p,poZjezdzie,kto,'zrzut schowanego paska');await p.screenshot({path:out+'/schowany.png'});
 }
}

export async function sprawdzPasek({browser:b,adres,sesja,out='output/pasek'}) {
mkdirSync(out,{recursive:true});
const results=[];
try {
for(const width of [320,360,390,414,768,1440])for(const theme of ['light','dark'])for(const scale of [100,140]){
 const p=await b.newPage({viewport:{width,height:900},serviceWorkers:'block'});
 await p.goto(adres);await p.evaluate(({theme,scale})=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.textScale=String(scale);},{theme,scale});
 await p.evaluate(()=>document.fonts.ready);const bar=p.locator('[data-pasek-przewijany]');await bar.waitFor();
 const sticky=await bar.evaluate(e=>getComputedStyle(e).position==='sticky');
 await przebieg({p,bar,sticky,out,width,theme,scale,kto:'gość'});
 results.push({width,theme,scale,sticky,kto:'gość',result:'PASS'});await p.close();
}
const p=await b.newPage({reducedMotion:'reduce'});await p.goto(adres);assert(await p.locator('[data-pasek-przewijany]').evaluate(e=>parseFloat(getComputedStyle(e).transitionDuration)<=0.001));await p.close();

/* STAN ZALOGOWANY. Mechanizm (CSS i JS) jest wspólny, więc nie powtarzamy
   pełnych 24 konfiguracji — to kupowałoby minuty CI za tę samą wiedzę.
   Bierzemy trzy szerokości, na których pasek zalogowanej osoby jest
   najciaśniejszy, i dokładamy przypadek, którego u gościa NIE MA:
   otwarte menu konta. Skrypt nie ma prawa schować paska, gdy człowiek
   ma w nim otwarte menu — pilnuje tego `details[open]`
   w `resources/js/pasek-przewijany.js`, a do tej pory nikt tego nie mierzył,
   bo gość menu konta nie ma. */
if(sesja){
 for(const width of [320,390,768])for(const scale of [100,140]){
  const kontekst=await b.newContext({storageState:sesja,viewport:{width,height:900},serviceWorkers:'block'});
  const p=await kontekst.newPage();
  await p.goto(adres);await p.evaluate((scale)=>{document.documentElement.dataset.textScale=String(scale);},scale);
  await p.evaluate(()=>document.fonts.ready);const bar=p.locator('[data-pasek-przewijany]');
  await bar.waitFor();
  const sticky=await bar.evaluate(e=>getComputedStyle(e).position==='sticky');
  await przebieg({p,bar,sticky,out,width,theme:'light',scale,kto:'zalogowana'});
  results.push({width,theme:'light',scale,sticky,kto:'zalogowana',result:'PASS'});
  await kontekst.close();
 }
 const kontekst=await b.newContext({storageState:sesja,viewport:{width:390,height:900},serviceWorkers:'block'});
 const p=await kontekst.newPage();
 await p.goto(adres);await p.evaluate(()=>document.fonts.ready);
 const bar=p.locator('[data-pasek-przewijany]');await bar.waitFor();
 const menu=bar.locator('details.topbar-konto');
 assert(await menu.count()>0,'zalogowany pasek nie ma menu konta — nie ma czego sprawdzać');
 await menu.locator('summary').first().click();
 assert(await menu.first().evaluate(e=>e.open),'menu konta się nie otworzyło');
 // Tu sprawdzamy BRAK zmiany, więc nie ma na co czekać „aż się stanie”:
 // `przewin` gwarantuje, że skrypt strony obsłużył przewinięcie, a potem
 // czekamy tylko na koniec przejść — dopiero wtedy stan jest ostateczny.
 await przewin(p,{do:1000});
 const zMenu=await stanPaska(p,'ustalony','zalogowana, otwarte menu konta','pasek z otwartym menu');
 assert(!zMenu.schowany,`pasek schował się z otwartym menu konta ${JSON.stringify(zMenu)}`);
 if(zMenu.pozycja==='sticky')assert(zMenu.y>=0,`pasek z otwartym menu zniknął z ekranu ${JSON.stringify(zMenu)}`);
 results.push({width:390,theme:'light',scale:100,kto:'zalogowana, otwarte menu konta',result:'PASS'});
 await kontekst.close();
}

writeFileSync(out+'/wyniki.json',JSON.stringify(results,null,2));console.log(results.length,'PASS + reduced motion');
}finally{}
}
