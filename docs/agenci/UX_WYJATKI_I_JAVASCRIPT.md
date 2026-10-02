# UX 50+: wyjątek menu trzech kropek i zasada JavaScriptu (D-053)

Przeniesione z `AGENTS.md` §5 bez zmian treści; zasada w skrócie i odnośnik do tego pliku zostają w `AGENTS.md`.

## Wyjątki D-051 i D-262 — pełny opis

Te dwie reguły mają dwa nazwane, udokumentowane wyjątki — oba na świadomą
decyzję właściciela:

- **D-051** — metryczka wersji i przełącznik motywu w stopce
  (`docs/DECISIONS.md`, D-051);
- **D-262** — tylko reguła 18 px i tylko panel moderacji: napisy pomocnicze
  15–16 px w czterech selektorach — `.side-nav-moderacja-naglowek`
  i `.sygnal-podglad-cytat` (`resources/css/app.css:1512` i `:1632`),
  `.tabela-kont .drobne` i `.stan-konta`
  (`resources/css/ekran-uzytkownikow.css:282` i `:294`). Numery linii
  wskazują komentarz z odwołaniem do D-262 nad regułą (stan z 25 września
  2026); przy rozjeździe wiążąca jest nazwa selektora. Przyciski i inne cele
  dotyku w panelu mają nadal ≥ 48 px (`docs/DECISIONS.md`, D-262).

To nie jest furtka ogólna: gdziekolwiek indziej w serwisie — także na innych
ekranach panelu moderacji i w publicznych widokach odwołań — te reguły
obowiązują bez zmian. Kolejny wyjątek wymaga nowej decyzji właściciela
i dopisania go tutaj.

## Menu „więcej” na karcie wpisu — uzasadnienie i granice

**Reguła „ikona nigdy nie jest jedynym opisem ważnej akcji" ma jeden nazwany
wyjątek: menu „więcej" na karcie wpisu** (`components/post-card.blade.php`,
`<details class="post-card-menu">`). Ten jeden przycisk to same trzy kropki,
bez widocznego napisu.

- **Powód.** To jest utrwalony wzorzec z Facebooka, a nasza grupa spędziła
  tam lata. Trzy kropki w rogu wpisu nie są dla niej ikoną do rozszyfrowania,
  tylko znakiem, który już zna. Decyzja właściciela z 12 września 2026,
  podjęta ze znajomością ryzyka — odwraca decyzję z 11 września, która
  dokładała tam napis „Więcej".

- **Granica.** Wyjątek dotyczy **wyłącznie tego jednego menu**. Nie obejmuje
  paska akcji pod wpisem, pasków nawigacji, przycisku zamykania, akcji
  moderacyjnych ani niczego innego — tam reguła obowiązuje bez zmian
  i pilnują jej osobne testy.

- **Co wyjątek zabiera, a czego nie.** Zabiera **widoczny napis**. Nie
  zabiera niczego czytnikowi ekranu: `aria-label` („Więcej przy tym wpisie")
  zostaje i jest wtedy jedyną nazwą dostępną tego przycisku. Nie zabiera też
  celu dotknięcia — przycisk dalej ma 48 × 48 px.
- **Czym to się różni od stanu sprzed 11 września.** Wtedy przyciskiem były
  trzy kropki wpisane z klawiatury, schowane przed czytnikiem ekranu — oko
  dostawało znak bez podpisu, czytnik podpis bez znaku. Teraz kropki rysuje
  komponent ikony, `aria-label` niesie pełną nazwę, a `AGENTS.md`
  i `docs/UX_50_PLUS.md` mówią o tym wprost, zamiast milczeć.
- **Pilnuje tego test** `KartaWpisuTest::test_menu_karty_to_same_kropki_ale_czytnik_ekranu_nie_traci_nic`.
  Rozszerzenie wyjątku na kolejny przycisk wymaga decyzji właściciela
  i wpisu w `docs/DECISIONS.md`, a nie dopisania klasy CSS.

## JavaScript — zmiana zasady z 9 września 2026

**Zmiana zasady, 9 września 2026 (D-053).** Wcześniej stało tu, że rejestracja,
logowanie, publikacja wpisu, przepis, komentarz i „Ugotowałem” **muszą działać
bez JavaScriptu**. Właściciel to zmienił i ma rację co do faktów: nasi
użytkownicy nie wchodzą tu z telefonu bez skryptów, tylko z Samsunga, Xiaomi
albo z komputera. Pełne uzasadnienie i skutki: **D-053** w `docs/DECISIONS.md`.

Obowiązuje teraz to:

1. **Newralgiczne formularze mogą wymagać JavaScriptu.** Rejestracja i logowanie
   stoją za Turnstile (D-050), a Turnstile bez skryptu nie istnieje. Wymóg jest
   świadomy: chroni serwis przed ruchem automatycznym.
2. **Gdziekolwiek indziej JavaScript jest mile widziany** — podgląd zdjęcia
   przed wysłaniem, licznik znaków, kadrowanie awatara. Nie trzeba tego
   uzasadniać ani dublować wersją bez skryptu.
3. **Czego nie wolno nigdy: martwego przycisku.** Jeśli coś bez skryptu nie
   zadziała, człowiek ma zobaczyć zdanie po polsku mówiące, CO ZROBIĆ, a nie
   formularz, który po kliknięciu milczy. `<noscript>` z konkretną instrukcją,
   nie z ogólnikiem „wymagany JavaScript”. Powód jest ten sam co dawniej i nie
   zniknął: przy słabym zasięgu skrypt bywa **nie dociągnięty** na telefonie,
   który JavaScript ma i ma go włączonego.
4. **Awaria po naszej stronie albo po stronie Cloudflare nie zamyka drzwi.**
   Gdy weryfikacja tokenu nie odpowiada, formularz przechodzi (D-050). Wymóg
   dotyczy skryptu u człowieka, nie sprawności cudzej usługi.
