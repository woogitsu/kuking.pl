# Zlecenia — gpt-zawieszone-konto

## 2026-09-20 21:49 — zadanie bieżące

Pełna treść: `C:\Users\matma\Documents\kuking-flota\_prompty\54-zawieszone-konto.txt`

#926 — zawieszone konto widzi formularze, które serwer i tak odrzuci. Wzorzec szerszy niż #902; tamto jest już rozwiązane i posłuży za wzór do skopiowania.

Gdy skończysz: dopisz meldunek do `meldunki/gpt-zawieszone-konto.md` i zajrzyj tutaj —
dopiszę kolejne zadanie na końcu tego pliku.

## 2026-09-20 21:51 — kolejne zadanie

Poprzednie przyjęte. Pełna treść nowego: `C:\Users\matma\Documents\kuking-flota\_prompty$2`

#928 — numer wersji aplikacji 0.68 podnoszą jednocześnie co najmniej dwie gałęzie. Pierwszy krok: ustal, w KTÓRYM pliku ten numer faktycznie żyje — żaden raport tego nie podaje, więc nie zgaduj. Potem zaproponuj sposób, który sprawi, że dwie gałęzie nie mogą cicho nadpisać sobie wersji przy scalaniu.

Gdy skończysz — meldunek do skrzynki i zajrzyj tutaj po następne.

---

## UWAGI AUDYTU — NIE POLECENIE (2026-09-21 07:54)

Z przebiegu 3 audytu przed kolejką (`skrzynka/meldunki/AUDYT-2026-09-21-0754.md`).
**To nie jest zlecenie.** Decyzja, co z tym zrobić, należy do Ciebie i do koordynatora.

- **[pkt 1 — dowód czerwieni poza repozytorium] `docs/product/ZAWIESZENIE_926.md`
  („Weryfikacja i wycofanie”) i `docs/product/ZAWIESZENIE_926_ODTWORZENIE.md`.**
  Piętnaście kontroli ujemnych opisano jako „PASS → FAIL z właściwego powodu → PASS”
  z logami w `gpt-zawieszone-konto-PLIKI/output/naprawa-926/`, a pomiar sprzed
  poprawki jako `output/pomiar-926/`. **Żaden z tych plików nie jest w drzewie
  gałęzi.** Gałąź ma jeden zbiorczy commit odzyskania, więc raport jest jedynym
  możliwym dowodem — i nie podaje ani jednej nazwy oblanej asercji. Porównawczo:
  `gpt/dziennik-wyjatkow` i `gpt-dr-baza` zacommitowały swoje JSON-y kontroli.
  Wystarczy dołożyć dowody **albo** wypisać w raporcie, co dokładnie padło
  i z jakim komunikatem.
- **[pkt 2, niski priorytet, na przyszłość] `app/Policies/UserPolicy.php:47-68`.**
  Memoizacja zbioru blokad w `request()->attributes` obowiązuje tylko dla `GET`.
  Dziś bez skutku, bo bramka `follow` jest wołana wyłącznie z HTTP
  (`SocialController` + cztery widoki). Gdyby kiedykolwiek zawołał ją worker
  kolejki, singleton `request` żyje przez cały proces i pamięć blokad stałaby się
  nieodświeżalna.

Sam kod i testy audyt ocenia wysoko: lista wyjątków `EnsureAccountIsActive` to
osiem konkretnych nazw tras (nie wzorzec), `cooking.reset` ma i trasę, i przycisk
z potwierdzeniem, i wiersz w macierzy pięciu ról, a
`test_private_notebook_choice_and_validation_preserve_entered_data` pilnuje, że po
błędzie walidacji wpisany opis wraca do formularza.

**Do Twojego bieżącego zadania #928 (numer wersji):** audyt ustalił, że numer żyje
w **`config/kuking.php:2761`** (`'etykieta' => 'Alfa 0.67'`), a regułę „każde
podbicie ma wpis w `CHANGELOG.md`” zapisano w komentarzu trzy linie wyżej
(`:2758-2760`). Podbijają go **cztery** gałęzie naraz — `gpt-zdjecia-publikacja`,
`gpt-ugotowalem-dostep`, `gpt-zdjecia-limity`, `gpt-zalegle` — **żadna nie dotyka
`CHANGELOG.md`**, a `git grep CHANGELOG origin/main -- tests` nie zwraca nic, więc
reguły nie pilnuje dziś żaden test.
