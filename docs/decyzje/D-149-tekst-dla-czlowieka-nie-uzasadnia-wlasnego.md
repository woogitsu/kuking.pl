## D-149 · Tekst dla człowieka nie uzasadnia własnego brzmienia — na ekranie tak samo jak w dokumencie prawnym

**Data:** 11 września 2026 · Audyt copy, `docs/research/audyt-copy-2026-09-11/` ·
PR #395 i #396 · Status: **obowiązuje** · rozwinięcie D-140

### Zasada

> **Zdanie o fakcie dotyczącym usługi zostaje — także niewygodne. Znika zdanie
> o procesie pisania tego tekstu i o naszym toku rozumowania.**

D-140 zdjęło z dokumentów prawnych notę o tym, kto dokumentu nie czytał:
informację o **procesie powstawania**, adresowaną do nas samych. Ta sama choroba
chodziła po całym interfejsie, tylko w innych słowach — i stąd rozszerzenie
zasady na cały tekst, który czyta człowiek.

### Co usunięto i z czego to zostało

**W dokumentach prawnych** (`resources/legal/*`): zdania tłumaczące, skąd wiemy
to, co piszemy, i dlaczego uważamy to za uczciwe.

| było | jest |
|---|---|
| „Nie zapisuje niczego na Twoim urządzeniu… Sprawdziliśmy to, czytając ten skrypt **linijka po linijce, a nie wierząc na słowo**…" | „Nie zapisuje niczego na Twoim urządzeniu — ani pliku cookie, ani nic w pamięci przeglądarki, więc nie ma czym Cię oznaczyć." |
| „**Uważamy, że nie ma to prawa tak zostać, i mówimy dlaczego.**…" | „**Tego liczenia otwarć nie da się wyłączyć z naszego kodu** — jest ustawieniem konta u dostawcy… Do tego czasu obrazek jedzie w każdym liście." |
| „…nie podajemy tu liczby godzin, bo **nie mamy dziś w serwisie nic, co ten termin mierzy i pilnuje**." | „Odpowiadamy bez zbędnej zwłoki, a sprawy poważne bierzemy pierwsze. Nie obiecujemy konkretnej liczby godzin." |

Zdjęte także: „i mówimy to **wprost**" (×3), „**Uczciwie** o granicy…",
„Opisujemy to, bo zachodzi", odesłanie do „wewnętrznego dokumentu
bezpieczeństwa", którego czytelnik nie ma, oraz to samo twierdzenie
o Cloudflare powtórzone trzy razy w jednym dokumencie.

**Na czterech ekranach** to samo w wersji produktowej: opis dla wyszukiwarki na
spisie tematów mówi, co na stronie jest, zamiast **jak ją sortujemy** (reguła
kolejności bez zmian i nadal widoczna na stronie); z `/o-kuking` zeszły trzy
zaprzeczenia zarzutom, których nikt nie postawił; z potwierdzenia wysłanej
wiadomości zeszło „nie zginie, nawet gdyby akurat nie działała poczta" — prawda
o naszej architekturze, ale podsuwa myśl, że poczta bywa nieczynna.

### Co zostało nietknięte — i to jest połowa tej zasady

Wszystko niewygodne: brak podpisanych umów powierzenia, brak inspektora ochrony
danych, nieustalony okres życia danych w kopiach zapasowych, sekcje „Źródła",
„Tego nie da się odwrócić". Zostało też „ludzi, którzy **naprawdę** gotują" —
to hasło serwisu, nie retoryka.

### Jak to jest pilnowane, żeby nie zamieniło się w zakaz słowa

Skan wzorców samouzasadniania (`DokumentyPrawneNieKlamiaTest`) ma kontrolę
**dwustronną**: **15 zdań wziętych dosłownie z `main`, które muszą oblewać,
i 18 zdań, które muszą przechodzić** — z trzema chronionymi zdaniami o brakach
na czele oraz z „ludzi, którzy naprawdę gotują". Ta kontrola od razu się
przydała: pierwsza wersja wzorca `wewnętrzn* dokument*` **przepuszczała zdanie,
które miała łapać**, bo odmiana „dokumen**cie**" nie pasowała do rdzenia.

Kontrola ujemna na całości: po cofnięciu `resources/legal/` — **3 padnięcia
z 26**, każdy z trzech dokumentów oblewa z wypisanymi cytatami. Po przywróceniu:
**26/26**.

**Zmiana wymaga:** niczego. Ta zasada nie jest sądem o stylu — jest odpowiedzią
na pytanie, po co czytelnik przyszedł.

📄 `docs/brand/COPY_STYLE.md` ·
`tests/Feature/DokumentyPrawneNieKlamiaTest.php` · D-140 · D-150 · D-152
