# Pierwszy ekran strony powitalnej — pomiar i kontrole ujemne

Data: 20 września 2026. Przyrząd: `scripts/hero-nad-zgieciem.mjs`.
Chromium z Playwrighta, gość bez sesji, `php artisan serve --no-reload`,
dane z `DemoSeeder`. Mierzona liczba: **dół przycisku „Zostań kuKINGiem"
względem dolnej krawędzi okna**.

Wymaganie: `docs/design/system-v3.1/uploads/KuKING-design-system-v3.1-poprawiony/04-strona-www/STRONA-WWW.md:121`
— „hasło i akcja mają być widoczne bez przewijania".

## Zakres jest decyzją właściciela

Naprawiamy i strzeżemy **wyłącznie skali tekstu 100%**. Przy 140% zgadzamy się
na przewijanie. Liczby przy 140% są niżej **zmierzone i wypisane**, ale nie
stoi za nimi żadna asercja — asercja zabetonowałaby zachowanie, którego nikt
nie wybrał.

## Trzy stany, ten sam przyrząd

Stan A to `534e0a51` (sprzed poprawki). Stan B to sam układ (CSS z poprawki,
akapit sprzed). Stan C to stan dzisiejszy: układ plus skrócony akapit.
Po pomiarze pliki runtime wróciły do stanu C — sprawdzone `md5sum` wobec
worktree, wszystkie trzy zgodne.

Dół przycisku (px) przy **skali 100%**:

| okno      | A — sprzed | B — sam układ | C — układ + krótszy tekst | wysokość okna | zapas w C |
|-----------|-----------:|--------------:|--------------------------:|--------------:|----------:|
| 320 × 568 |      776,4 |         677,9 |                     541,5 |           568 |      26,5 |
| 360 × 640 |      653,9 |         613,9 |                     511,6 |           640 |     128,4 |
| 375 × 667 |      656,2 |         616,2 |                     514,0 |           667 |     153,0 |
| 414 × 736 |      628,2 |         588,2 |                     520,0 |           736 |     216,0 |

Przy **skali 140%** (zmierzone, nieasercjonowane):

| okno      | A — sprzed | B — sam układ | C — dziś |
|-----------|-----------:|--------------:|---------:|
| 320 × 568 |     1228,9 |        1188,9 |    902,5 |
| 360 × 640 |     1011,6 |         971,6 |    780,7 |
| 375 × 667 |     1011,6 |         971,6 |    780,7 |
| 414 × 736 |      976,6 |         869,1 |    678,2 |

Czyli: przy 320 px sam układ oddał **98,5 px**, a skrócenie akapitu kolejne
**136,4 px**. Akapit sprzed poprawki zajmował przy 320 px **272,8 px**, po
skróceniu **136,4 px**. Przy 140% każda liczba też spadła, choć nikt tego
nie wymagał; 414 px mieści się tam dziś z zapasem 57,8 px, a 320, 360 i 375 px
dalej wymagają przewijania — zgodnie z decyzją właściciela.

Pismo i cele dotknięcia są w stanie C nietknięte: nagłówek 28 px, akapit 22 px,
przycisk 20 px pisma i 78 px wysokości (progi `docs/UX_50_PLUS.md`: 18 px i 48 px).

## Kontrola ujemna 1 — układ (`kontrola-ujemna-uklad.json`)

Przyrząd: `scripts/kontrola-ujemna.sh`. Mutacja w `resources/css/marka-ekrany.css`:
`padding-block-start: var(--spacing-6);` → `padding-block-start: 240px;`.
Sprawdzany strażnik: `node scripts/hero-nad-zgieciem.mjs`.

```
werdykt:                    POTWIERDZONA
kontrola dodatnia przed:    PASS
mutacja weszła:             1 podmiana, MD5 456d9ea2… → 37173101…
wynik po mutacji:           FAIL (kod 1), z oczekiwanego powodu
                            CTA_PONIZEJ_ZGIECIA 320×568/skala 100%:
                            dół przycisku 757.5 px, okno 568 px (brakuje 189.5 px)
kontrola dodatnia po:       PASS
przywrócenie:               MD5 i mtime porównane ze stanem sprzed przebiegu
```

Pełny przebieg PASS → FAIL → PASS. `cmp` pliku w runtime wobec worktree po
przebiegu: identyczne bajt w bajt.

W obu zapisach JSON pole `"przywrocenie"` stoi na `"nie wykonane"` — to nie
jest wynik, tylko kolejność: `scripts/kontrola-ujemna.sh` zapisuje JSON
PRZED wyjściem, a przywracanie siedzi w `trap` na EXIT i wykonuje się później.
Werdykt przywrócenia jest w wyjściu na ekranie i w `cmp`/`md5sum` wyżej.

## Kontrola ujemna 2 — tekst (`kontrola-ujemna-tekst.json`)

Mutacja w `resources/views/pages/landing.blade.php`: przed zdanie z
`GLOS_MARKI.md` wraca usunięty opis. Sprawdzany strażnik:
`vendor/bin/phpunit --filter PierwszyEkranMiesciPrzyciskTest`.

Kroki 1–3 wyszły: PASS na nietkniętym źródle, mutacja weszła (1 podmiana,
MD5 9f94f5c8… → 78f53a3d…), test oblał z oczekiwanego powodu — „Akapit hasła
ma więcej niż jedno zdanie".

**Krok 4 dał czerwień, która NIE JEST regresją produktu** i zapis JSON
mówi `PRZYWROCENIE_NIEUDANE`. Przyczyna jest w samym przyrządzie:
`scripts/kontrola-ujemna.sh` przywraca plik przez `cp -p`, czyli razem z
**mtime sprzed przebiegu**. Laravel rozstrzyga świeżość skompilowanego widoku
Blade po mtime, więc po przywróceniu w `storage/framework/views` zostaje widok
zbudowany ze ZMUTOWANEGO źródła i uchodzi za aktualny. Sprawdzone osobno:

```
MD5 runtime:  9f94f5c8f0bbefc3c29c719225927be0
MD5 worktree: 9f94f5c8f0bbefc3c29c719225927be0
cmp: IDENTYCZNE bajt w bajt
php artisan view:clear && vendor/bin/phpunit --filter PierwszyEkranMiesciPrzyciskTest
→ OK (5 tests, 50 assertions)
```

Czyli źródło wróciło bajt w bajt, a strażnik po wyczyszczeniu skompilowanych
widoków znów przechodzi. Sam przyrząd wymaga poprawki (czyszczenie widoków
między krokami albo pominięcie odtwarzania mtime dla plików Blade) — to jest
osobna sprawa, nie sprawa tej poprawki.
