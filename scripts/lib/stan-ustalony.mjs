/* CZEKANIE NA STAN, NIE NA ZEGAR.

   Stałe `waitForTimeout(300)` albo „dwie klatki” mierzą, czy maszyna była
   akurat szybka, a nie, czy strona działa. Zmierzone 24 września 2026
   (Chromium 141, `reducedMotion: 'reduce'`): po zmianie `data-theme`
   i `data-text-scale` dokument ma SZEŚĆ trwających przejść CSS (color,
   background-color, font-size…), po pierwszej klatce `getComputedStyle`
   wciąż oddaje stare wartości, a ostatnie przejście znika dopiero w czwartej.
   Powód: `tokens.css` przy `prefers-reduced-motion` skraca KAŻDE przejście do
   0.01ms na `*`, a domyślne `transition-property` to `all` — więc przejście
   dostaje każda właściwość, także kolor i rozmiar pisma. Sonda z
   `transition: none` widzi wartość docelową od razu, element strony — nie.
   Na zajętym runnerze te same kroki trwają dłużej i pomiar trafia w środek.

   Stąd jeden wzorzec dla kontroli przeglądarkowych: czekaj, aż strona
   OSIĄGNIE oczekiwany stan i nie będzie w niej trwającego przejścia; limit
   czasu jest tylko bezpiecznikiem, a porażka niesie zmierzone wartości,
   żeby dało się ją zrozumieć bez ponownego uruchomienia.

   `warunek` i `pomiar` wykonują się W STRONIE — nie mogą sięgać do zmiennych
   z Node. Dostają `(arg, przejscia)`, gdzie `przejscia(element?)` zwraca
   trwające przejścia CSS dokumentu albo poddrzewa elementu. `warunek` ma być
   SYNCHRONICZNY (zwrócona obietnica jest prawdziwa od razu) — fonty sprawdzaj
   przez `document.fonts.status === 'loaded'`, nie przez `await fonts.ready`. */

export const TRWAJACE_PRZEJSCIA = `((element) => (element ? element.getAnimations({ subtree: true }) : document.getAnimations())
  .filter((a) => a instanceof CSSTransition && a.playState !== 'finished')
  .map((a) => ({
    wlasciwosc: a.transitionProperty,
    stan: a.playState,
    oczekuje: a.pending,
    element: a.effect?.target ? a.effect.target.tagName.toLowerCase() + (a.effect.target.className && typeof a.effect.target.className === 'string' ? '.' + a.effect.target.className.trim().split(/\\s+/).join('.') : '') : null,
  })))`;

/* FUNKCJA, NIE WYRAŻENIE. `waitForFunction('…')` z łańcuchem strona wykonuje
   jak `eval`, a CSP aplikacji (`script-src 'self' 'nonce-…'`, bez
   `unsafe-eval`) to odrzuca: „Refused to evaluate a string as JavaScript”.
   Obiekt funkcji Playwright przekazuje inaczej i CSP go przepuszcza —
   sprawdzone na stronie z `php artisan serve`. Składamy więc funkcję w Node. */
function wywolanie(funkcja) {
  return new Function('arg', `return (${funkcja})(arg, ${TRWAJACE_PRZEJSCIA});`);
}

/* Czeka (co klatkę), aż `warunek` będzie prawdziwy, i oddaje `pomiar`.
   Po `limitMs` NIE rzuca gołego TimeoutError: oddaje `{ ustalony: false,
   zmierzone }`, a o porażce decyduje wywołujący — tak, żeby zachował własny
   kod błędu (np. `P581_SKALA_MOTYW`) i własną asercję. */
export async function poczekajNaStan(page, { warunek, pomiar, arg = null, limitMs = 10_000 }) {
  let ustalony = true;
  try {
    await page.waitForFunction(wywolanie(warunek), arg, { polling: 'raf', timeout: limitMs });
  } catch (blad) {
    if (blad?.name !== 'TimeoutError') throw blad;
    ustalony = false;
  }
  const zmierzone = await page.evaluate(wywolanie(pomiar), arg);

  return { ustalony, zmierzone };
}

/* Wariant rzucający: porażka to komunikat z opisem, limitem i pomiarem. */
export async function wymagajStanu(page, { opis, ...reszta }) {
  const { ustalony, zmierzone } = await poczekajNaStan(page, reszta);
  if (!ustalony) {
    throw new Error(`${opis} — stan nie ustalił się w ${reszta.limitMs ?? 10_000} ms. Zmierzone: ${JSON.stringify(zmierzone)}`);
  }

  return zmierzone;
}
