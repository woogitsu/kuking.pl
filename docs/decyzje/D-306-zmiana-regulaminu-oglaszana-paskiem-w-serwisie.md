## D-306 — Zmiana regulaminu ogłaszana paskiem w serwisie, wersja z datą w konfiguracji (#1811, 26 września 2026)

**Data:** 26 września 2026 · Decyzja właściciela (26.09.2026) · Status: **obowiązuje**

### Decyzja

Zmianę regulaminu ogłaszamy **komunikatem w serwisie, bez maili**:

- wersja regulaminu to data w `kuking.zgody.wersja_regulaminu`, tym samym
  kształtem co `wersja_polityki` (D-072): dzień stanu dokumentu z nagłówka
  „opisuje stan serwisu na …”, podbijany ręcznie razem z nim;
- dokument ma na górze sekcję „Co się zmieniło” (kotwica `#co-sie-zmienilo`)
  z wpisem datowanym dniem wersji; `ZmianaRegulaminuTest` pilnuje zgodności
  nagłówka, sekcji i konfiguracji;
- zalogowane konto założone przed dniem wersji widzi na każdym ekranie pasek
  „Zmieniliśmy regulamin. Zobacz, co się zmieniło” z przyciskiem „Zamknij”
  (POST, bez JS); zamknięcie zapisuje wersję w
  `users.terms_notice_dismissed_version` i przy tej wersji pasek nie wraca.
  Konto założone w dniu wersji albo później paska nie dostaje;
- zamknięcie paska **nie jest akceptacją** regulaminu — to ślad, że komunikat
  dotarł. Eksport: `konto.pasek_zmiany_regulaminu_zamkniety_dla_wersji`;
  wymazanie konta zeruje pole. Rollback migracji odmawia, gdy ktoś pasek
  zamknął (D-088, wzorem `pwa_prompt_state`).

Pierwsza wersja: 26 września 2026 — dopisany opis doboru wpisów i odnośnik do
„Jak dobieramy wpisy” (D-305). Zmiana opisuje działanie serwisu i nie dodaje
obowiązków, dlatego weszła od razu; §11 regulaminu (14 dni przy zmianach
istotnych) zostaje bez zmian — **pytanie do właściciela/prawnika**, czy przy
następnej zmianie istotnej pasek ma się pokazywać z wyprzedzeniem (data
wejścia w życie osobno od daty publikacji).

**Rozstrzygnięte 26 września 2026 w D-327:** tak — przy zmianie istotnej
data wejścia w życie jest osobna od daty publikacji (+14 dni).

### Wycofanie

Kod: `ZmianaRegulaminu`, `ZmianaRegulaminuController`, trasa
`terms.notice.dismiss`, komponent `pasek-zmiany-regulaminu`. Kolumny nie
cofać, gdy ktoś pasek zamknął (migracja odmówi).

📄 `app/Domain/Zgody/ZmianaRegulaminu.php` · `resources/views/components/pasek-zmiany-regulaminu.blade.php` ·
`database/migrations/2026_09_26_120000_add_terms_notice_dismissed_version_to_users.php` ·
`tests/Feature/ZmianaRegulaminuTest.php` · `resources/legal/regulamin.md` · D-072 · D-088 · D-305
