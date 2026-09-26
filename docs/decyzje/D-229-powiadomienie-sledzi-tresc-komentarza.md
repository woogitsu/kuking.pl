## D-229 — Powiadomienie śledzi treść komentarza (#758, 20 września 2026)

**Przenumerowane z D-223.** Ta decyzja nosiła pierwotnie numer D-223 — pod tym
samym numerem stała też rodzina strażnika martwej kaskady CSS (`flota/kaskada`
/ `flota/martwe-kaskady` / `flota/scal-915`). Rozstrzygnięcie właściciela
21 września 2026: numer D-223 zostaje przy rodzinie kaskady, ta decyzja
przechodzi na D-229 (pierwszy naprawdę wolny numer, sprawdzony przeglądem
wszystkich gałęzi repozytorium). **Kryterium: koszt przeniesienia.** Rodzina
kaskady miała w kodzie **16 odwołań** do D-223, ta decyzja tylko **13** —
przenosi się ta strona, po której trzeba poprawić mniej miejsc.

Decyzja właściciela. Wycinek treści komentarza w powiadomieniu jest **liczony
przy wyświetlaniu, z aktualnej treści** — jedno źródło prawdy, nie zamrożona
kopia w `notifications.data`. Do tej zmiany `PublishComment` wpisywał do
`data.excerpt` 120 znaków z chwili publikacji i nikt tego nigdy nie odświeżał:
autor poprawiał „dodaję dwie łyżki masła" na „dwie łyżeczki" w dozwolonym
oknie 15 minut, wątek pokazywał poprawkę, a powiadomienie dalej mówiło „łyżki".

**Eksport RODO zmienia się tak samo**, i to jest część decyzji, a nie jej
skutek uboczny: paczka z danymi ma pokazywać, co o kimś trzymamy **dziś**,
a nie historyczną wersję. Zamrożony wycinek opisywałby stan, którego w bazie
już nie ma.

Ta decyzja **uchyla dawne zdanie o powiadomieniu jako migawce zdarzenia
z przeszłości — ale wyłącznie dla wycinka treści**. D-052 w części
o nieuruchamianiu skutków ubocznych przy edycji zostaje w mocy: edycja
komentarza nadal nie zleca ponownej analizy moderacyjnej, nie tworzy nowego
powiadomienia i nie przywraca `read_at` do `null`.

> **Adnotacja (25 września 2026, B6-07):** zdanie powyżej o ponownej
> analizie przestało być prawdziwe — **D-256** (24 września 2026, #909)
> zastąpiła je w tej części: `CommentController::update()` zleca
> `PrzeanalizujTresc::dlaKomentarza()`, gdy edycja rzeczywiście zmienia
> tekst komentarza. Reszta zdania (brak nowego powiadomienia, `read_at`
> bez zmiany) obowiązuje bez zmian.

Granica z #757 obowiązuje niezależnie i jest ważniejsza od tej decyzji:
komentarz usunięty (soft delete albo `body_removed_at` przy usunięciu
komentarza z odpowiedziami), ukryty przez moderację albo niedostępny dla
odbiorcy **nadal nie pokazuje treści** — ani na ekranie, ani w paczce.
Brak żywego wycinka **nigdy** nie sięga po starą kopię z `data` jako plan
zapasowy; dla tych typów powiadomień `data.excerpt` w ogóle nie jest już
czytany, a nowe wiersze przestają go zapisywać.

Koszt liczymy zbiorczo (D-196): `Notification::zyweWycinkiKomentarzy()`
dociąga wycinki **jednym zapytaniem** na całą stronę listy i jednym na cały
eksport, wzorem `NotificationController::decyzje()`. Zmierzone:
lista powiadomień 16 → 17 zapytań przy 2 wierszach i 66 → 67 przy 12
(koszt wiersza bez zmiany, 5 zapytań — to oś #759, nie ta zmiana);
eksport 25 → 26 zapytań, niezależnie od liczby powiadomień.

**Stare kopie znikają z bazy, nie tylko z ekranu.** Migracja danych
`2026_09_23_120000_usun_zamrozone_wycinki_komentarzy` zdejmuje `excerpt`
z `notifications.data` wyłącznie w `comment.created` i `comment.replied` —
„nie trzymamy", a nie tylko „nie czytamy". Decyzja właściciela z 23 września
2026: wchodzi **bez kopii bazy** („to jeszcze nie produkcja, nie ma
prawdziwych użytkowników"), więc skasowanych wycinków nic już nie odtworzy.
`down()` jest świadomie pusty i nie odmawia — uzasadnienie według D-088
w `docs/DATABASE.md`, w sekcji tej migracji.
