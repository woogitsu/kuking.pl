## D-303 — Ustawienia powiadomień dotyczą WYŁĄCZNIE kanałów zewnętrznych; Web Push przez VAPID (issue #35, 26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · **Decyzja właściciela** ·
Dotyczy **#35**, `AGENTS.md` §1, `docs/product/RETENTION_LOOPS.md` §3.2–3.3

**Problem.** AGENTS.md §1 mówił: „ustawień użytkownika na tej liście nie ma
i mieć nie ma" — a #35 i RETENTION_LOOPS §3.3 zakładały przełączniki per typ
i „wyłącz wszystkie". Bez rozstrzygnięcia każdy kanał poza serwisem albo
łamałby AGENTS.md, albo wysyłał bez możliwości wyłączenia.

**Decyzja.**

1. **Powiadomienia w serwisie — bez zmian.** Żadnych ustawień per typ,
   żadnego wyłącznika. „Ugotowałem" zawsze powiadamia autora (AGENTS.md §1).
2. **Ustawienia dotyczą WYŁĄCZNIE kanałów zewnętrznych** (Web Push, w przyszłości
   ewentualnie e-mail o zdarzeniu): prosty przełącznik „włącz/wyłącz" per kanał,
   **cisza nocna** (domyślnie 21–8, Europe/Warsaw, do zmiany przez człowieka)
   i **dzienny limit** liczby pushy (domyślnie 1, wybór z listy 1/2/3/5).
   Ekran: `/ustawienia/powiadomienia`. Bez ustawień per typ także tutaj.
3. **Web Push przez standard VAPID**, bez pośrednika (`minishlink/web-push`).
   Klucze w zmiennych środowiska; **brak klucza = funkcji nie ma** (ani ekranu,
   ani przycisku, ani wysyłki). `KUKING_POWIADOMIENIA_ZEWNETRZNE` przestaje być
   bramką uruchomienia (domyślnie `true`) i zostaje awaryjnym wyłącznikiem.
4. **Zgoda przeglądarki dopiero po kliknięciu** „Włącz powiadomienia na tym
   urządzeniu" w ustawieniach. Nigdy przy wejściu na stronę, nigdy własnymi
   okienkami, bez ponawiania po odmowie. (RETENTION_LOOPS §3.2 mówi „nie
   wcześniej niż po 3. wpisie" — przy zgodzie wyłącznie z ustawień ten próg
   jest zbędny: nikt nie zobaczy prośby, o którą sam nie poprosił.)
5. **Pushem idą tylko odzewy na własne treści:** „Ugotowałem" z mojego przepisu,
   odpowiedź na mój komentarz / w rozmowie pod moją treścią, odpowiedź na moje
   pytanie. Granice z AGENTS.md §1 obowiązują same z siebie — push powstaje
   tylko z wiersza, który `NotifyUser` już zapisał, i przechodzi przez
   `Notification::visibleTo()`.

**W kodzie.** `App\Domain\Notifications\Push\KanalPush` (dostępność, typy,
lista hostów usług push przeciw SSRF), `App\Jobs\WyslijPowiadomieniePush`
(jedno zadanie na odbiorcę, grupowanie, odłożenie zamiast skasowania,
404/410 kasuje subskrypcję, bez ponawiania), `TerminPowiadomieniaZewnetrznego`
(cisza i limit — teraz z wartościami człowieka), `TransportPush` z fałszywą
implementacją w testach. Schemat: `push_subscriptions`,
`ustawienia_powiadomien_zewnetrznych`, `notifications.push_wyslano_at`
(`docs/DATABASE.md`). Paczka RODO: sekcja `powiadomienia_poza_serwisem` bez
adresów i kluczy; `EraseAccountData` kasuje oba rodzaje wierszy. Klucz
publiczny dostaje web i worker, prywatny tylko worker (`.railway/railway.ts`).

**Zmiana wymaga:** nowej decyzji właściciela. W szczególności ustawienia per
typ albo jakikolwiek przełącznik dla powiadomień w serwisie wymagają zmiany
AGENTS.md §1 i testu `UgotowalemZawszePowiadamiaAutoraTest`.

### Uzupełnienie: wylogowanie gasi push na tym urządzeniu (#1979, 26 września 2026)

**Problem.** Subskrypcja Web Push należy do przeglądarki, nie do sesji.
Po „Wyloguj się" na wspólnym komputerze wiersz `push_subscriptions` dalej
wskazywał tę przeglądarkę, więc prywatne „X — ugotowane z Twojego
przepisu…" pokazywało się osobie, która siedzi przy nim potem.

**Rozstrzygnięcie.** Świadome „Wyloguj się" wyłącza push na urządzeniu,
z którego człowiek wychodzi — bez dodatkowego pytania (jedna prosta akcja,
bez okna „czy na pewno"; ekran ustawień mówi o tym jednym zdaniem).
Wygaśnięcie sesji **nie** wyłącza: telefon, na którym sesja po prostu minęła,
dostaje dalej. Inne urządzenia tej osoby — bez zmian.

Serwer rozpoznaje urządzenie sam, bez polegania na skrypcie: identyfikator
wiersza trafia do sesji przy włączeniu (`OdlaczUrzadzeniePush::KLUCZ_SESJI`).
Skrypt formularza wylogowania dokłada adres subskrypcji (pokrywa
przeglądarkę włączoną w dawnej sesji) i wywołuje `unsubscribe()`; każdy błąd
albo zawieszenie po 3 s kończy się zwykłym wylogowaniem. Kasowanie działa
wyłącznie w obrębie `$user->pushSubscriptions()` — adres albo identyfikator
cudzego wiersza niczego nie skasuje. Adres nie trafia do HTML-a (pole
w formularzu jest puste do chwili wysłania).

**Czego to nie zmienia.** Treść pushu zostaje z imieniem i nazwą potrawy
(RETENTION_LOOPS §3.3) — ukrywanie jej na ekranie blokady wymagałoby
osobnej decyzji właściciela. Osoba, która zaloguje się w przeglądarce
z subskrypcją kogoś, kto się **nie** wylogował (sesja wygasła), widzi
„wyłączone" i może przepiąć przeglądarkę na siebie przyciskiem „Włącz";
osobne ostrzeżenie o cudzej subskrypcji — do osobnego issue.

**W kodzie.** `App\Domain\Notifications\Push\OdlaczUrzadzeniePush`,
`LoginController::destroy`, `components/wyloguj.blade.php`,
`resources/js/powiadomienia-push.js` (`przygotujWylogowanie`). Testy:
`WylogowanieWylaczaPushNaUrzadzeniuTest`, `powiadomienia-push.test.mjs`.

### Wycofanie
Usunąć klucze VAPID ze zmiennych środowiska — po restarcie usług ekran i wysyłka znikają,
bez zmiany kodu. Wycofanie migracji odmawia, dopóki ktoś ma zapisane własne
godziny ciszy lub limit (D-088; instrukcja w komunikacie migracji).
