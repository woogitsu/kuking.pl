# Sprawdzenie audytu z 10.09.2026 — co potwierdziłem, a co jest nieprawdą

**Kto sprawdzał:** agent Claude Code, sesja z 10.09.2026, na `origin/main` @ `e3cf6ab`.
**Po co ten plik:** audyt jest materiałem ZEWNĘTRZNYM. Zanim z niego powstaną
zadania, trzeba go potraktować tak samo jak każde inne twierdzenie o kodzie:
sprawdzić przy pliku, nie uwierzyć na słowo. Jedna teza z części P0 okazała
się nieprawdziwa i gdyby nikt jej nie sprawdził, poszlibyśmy budować rzecz,
która już istnieje.

Reguła zostaje: **audyt zewnętrzny nie jest źródłem prawdy o kodzie, jest
zestawem hipotez do sprawdzenia.** Ten sam nawyk co przy PR-ach agentów.

## Potwierdzone na aktualnym `main`

Każde z poniższych sprawdziłem w pliku wskazanym przez audyt.

| Znalezisko | Dowód |
|---|---|
| S2 — brak `TrustHosts` | `bootstrap/app.php:78` mówi tylko, że „właściwym zamknięciem tego jest `TrustHosts`"; w repo nie ma wywołania `trustHosts` |
| SEO/PWA-01 — wymuszona orientacja | `public/manifest.webmanifest:10` → `"orientation": "portrait-primary"` |
| SEO/PWA-02 — sitemap gubi autorów samych przepisów | `SitemapController:88` → `whereHas('user.posts', …)`, bez odpowiednika dla `recipes` |
| SEO/PWA-05 / copy §4 — dwa nazewnictwa | manifest ma `Mój zeszyt` / `Zeszyt`, nawigacja mówi `Moje` |
| MOD-01 — brak priorytetu w kolejce | w `database/migrations/` nie ma kolumny `priority`/`severity` dla `reports` |
| MOD-04 — `triage`/`reviewing` istnieją, ale przepływ ich nie używa | stałe są w `Report.php:23,25`, panel je liczy (`KolejkiPanelu`), ale nic ich nie NADAJE |
| DB2 — rollback przywraca groźny domyślny opt-in | `2026_09_07_400000_default_weekly_digest_to_off.php:68` → `SET DEFAULT true` |
| copy §6 — metadane szkieletu Laravela | `composer.json:3,5` → `laravel/laravel`, „The skeleton application…" |
| supply chain §6 — deklaracja PHP szersza niż target | `composer.json:9` → `"php": "^8.3"`, a CI i obraz to 8.4 |
| CI1 — PHPStan na poziomie 1 | `phpstan.neon:63` → `level: 1` |
| supply chain §1 — audit podatności nie blokuje | `ci.yml:850,855` → `continue-on-error: true` przy `composer audit` i `npm audit` |
| A3 — śmieci w korzeniu | `object_key` (0 B) i `R1-tagi-kopia.md` (47 kB) leżą w root |

## NIEPRAWDA — znalezisko P0 nr 2 z audytu 10 (i BRAMKA D z raportu końcowego)

Audyt twierdzi: *„regulamin obiecuje odpowiedź każdemu reporterowi, a produkt
tego nie robi"* i klasyfikuje to jako **P0 blokujące start**.

**To jest nieprawda na `main`.** Pętla zgłaszającego jest zamknięta w kodzie od
issue #10:

- `app/Domain/Moderation/Actions/NotifyReporterReceipt.php` — potwierdzenie
  przyjęcia, wołane z `ReportContent.php:74` (konstruktor), czyli po ZWYKŁYM
  przycisku „Zgłoś", nie tylko z formularza DSA;
- `app/Domain/Moderation/Actions/NotifyReporterDecision.php` — informacja
  o rozstrzygnięciu, wstrzykiwana do `Admin/ModerationController.php:40`
  i wołana przy decyzji;
- `app/Domain/Moderation/OdpowiedzDlaZglaszajacego.php` — jedna treść dla
  listu i dla ekranu, z pouczeniem z art. 16 ust. 5;
- numer sprawy: `App\Support\NumerSprawy` + kolumna `numer_sprawy`;
- podgląd stanu sprawy dla zgłaszającego: trasy `reports.mine`
  i `reports.mine.show` (`/zgloszenia`, `/zgloszenia/{report}`) za
  `ReportPolicy`;
- znaczniki dowodowe: `receipt_sent_at`, `decision_sent_at`;
- testy: `tests/Feature/ZglaszajacyDostajeOdpowiedzTest.php`,
  `OdpowiedzDlaZglaszajacegoMowiPrawdeTest.php`,
  `ZglaszajacyMaDostepDoSkargTest.php`.

Czyli wszystkie pięć kroków, których audyt się domaga (potwierdzenie, numer
sprawy, stan, decyzja, pouczenie), już są.

### Skąd wzięła się pomyłka — i dlaczego to jest gorsze niż sama pomyłka

Audytor nie zmyślił. Przeczytał `docs/legal/MODERATION_PLAYBOOK.md`, który
w trzech miejscach (wiersze 124–126, 281, 355) nadal twierdzi, że osoba
klikająca zwykłe „Zgłoś" **nie dostaje nic** i że moderator musi napisać do
niej maila ręcznie. Playbook opisuje stan sprzed #10.

Prawdziwe znalezisko jest więc inne i nadal jest nasze:

> **Instrukcja operacyjna moderatora kłamie na temat własnego produktu.**

Skutek jest realny, nie akademicki: moderator idący za playbookiem albo
napisze do zgłaszającego drugi raz ręcznie, albo — co gorsza — uzna, że
obietnicy z regulaminu §7 nie da się dotrzymać, i przestanie ją traktować
poważnie. To dokładnie ten dryf dokumentacji, który audyt sam opisuje jako
najważniejsze ryzyko w części 13.

**Zadanie:** poprawić playbook do stanu faktycznego i dołożyć test, który
wiąże obietnicę z regulaminu §7 z realnym zachowaniem serwisu — żeby następna
osoba czytająca playbook nie musiała czytać kodu, a rozjazd oblewał się w CI.
Nie budować lifecycle'u od nowa.

## Czego nie sprawdzałem z repozytorium, bo sprawdzić się stąd nie da

Bramki A, B, C, F, G, H (R2, backup, restore drill, direct origin, monitoring,
przegląd prawny) są operacyjne: dowód leży w panelach Railway/Cloudflare/
EmailLabs i w wykonanym teście, nie w plikach. Audyt ma tu rację co do jednego
i to jest jego najmocniejsza teza: **`NIE WIEMY` liczy się jako nieprzejście
bramki, nie jako sukces.**

Jedna rzecz z tej grupy została w tej sesji sprawdzona z zewnątrz i wynik jest
częściowo dobry: nowe zdjęcia na produkcji idą przez podpisany adres R2 (302 →
`…r2.cloudflarestorage.com…` → 200 `image/webp`), żądanie bez podpisu i żądanie
oryginału są odrzucane, `cdn.kuking.pl` nie istnieje w DNS. Stare zdjęcia nadal
serwuje PHP z wolumenu, więc `kuking:przenies-zdjecia` pozostaje do wykonania,
a bramka #120 do wypełnienia komendą `kuking:bramka-r2 --zapis`.

## Co z tego audytu jest już w toku

- MOD-03 (#243, przenoszenie uzasadnienia między sprawami) — PR #256, otwarty.
- P1 5.3 / infra §5 (`failed_jobs` poczty bez alarmu) — PR #253, otwarty.
- UX2 (dwa pola nazwy) — decyzja właściciela: dwa pola zostają, nazwa jest
  podpowiadana z imienia; PR #265.
- UX3 (gęsty landing przed „Jak działa") — w toku.
- PROD3 / P1 5.6 („Tematy" jako jawne odkrywanie) — do wystawienia jako issue.
- CI2 (#262, zbieżność na współdzielonym runnerze) — issue otwarte.

---

# Warstwa druga i trzecia — i co audytor zrobił z moją korektą

**Dopisane 10.09.2026, po otrzymaniu raportów 15–29.**

## Audytor przyjął korektę i poprawił własne raporty

Powyżej napisałem, że teza P0 „produkt nie informuje zwykłego zgłaszającego"
jest nieprawdziwa, i pokazałem, gdzie w kodzie stoi zamknięta pętla.

Audytor sprawdził to u siebie i **usunął ten P0 z czterech swoich raportów**
(09, 10, 14 i 28), a w raporcie 29 zapisał wprost, czego dotyczyła pomyłka
i że prawdziwym znaleziskiem jest dryf `MODERATION_PLAYBOOK.md`. Zostawił to
jako **P1 dokumentacyjno-operacyjny** z adnotacją „nie tworzyć nowego
reporter lifecycle".

Warto to zanotować, bo mówi coś o wartości obu stron tego układu: audyt
zewnętrzny wychwycił rzeczy, których nie widzieliśmy z wewnątrz, a
sprawdzenie przy pliku wychwyciło jedną tezę, której nie było. **Żadna
z tych dwóch rzeczy nie zadziałałaby sama.**

## Potwierdzone przeze mnie w kodzie z warstwy drugiej i trzeciej

| Znalezisko | Dowód |
|---|---|
| **AUTH-01 / RACE-01** — potwierdzenie zmiany adresu może wygrać z anulowaniem | `ConfirmEmailChange` dostawał MODEL z kontrolera i nigdy nie czytał wiersza ponownie pod blokadą; `CancelEmailChange` kasowało go **bez żadnej** blokady. Kontrola ujemna: po usunięciu rewalidacji adres konta faktycznie zmienia się mimo anulowania. Naprawione (D-079). |
| **AUTH-02 / RACE-02** — równoległy link do logowania zdradza istnienie konta | **Zmierzone**, nie oszacowane: przy wymuszonym konflikcie adres z kontem dostawał **500**, adres bez konta **302**. Nic tego wyjątku nie przechwytywało. Formularz zaprojektowany jako nieodróżnialny odpowiadał więc na pytanie „czy tu jest konto". Naprawione (D-075). |
| **MAIL-01 / RACE-03** — dobowy budżet listów nie jest sufitem | `jestMiejsce()` w linii 160 i `zajmij()` w linii 170 tego samego kontrolera. `Cache::increment()` jest atomowy jako jedna operacja, ale sprawdzenie i zajęcie nie są atomowe jako para. Naprawione (D-076). |
| **QUEUE-01 / MAIL-02 / RACE-04** — digest może wysłać dwa razy | `Mail::queue()` w pętli, a `oznaczWyslane()` **jednym zapytaniem po całej pętli**. Awaria w środku kwalifikuje te same osoby ponownie. `withoutOverlapping()` chroni przed dwoma przebiegami JEDNOCZEŚNIE, nie przed kolejnym po awarii. Naprawione (D-077). |
| **MAIL-03** — sygnał „digest wysłany" znaczy „zakolejkowany" | Sygnał zapisywany dziewięć linii po `Mail::queue()`. Naprawione (D-078). |
| **RACE-05 / QUEUE-04** — brak bariery „jeden aktywny eksport" | `data_exports` miało CHECK na `status` i indeks `(user_id, created_at)`, a **ani jednego ograniczenia unikalności**. Naprawione (D-078). |
| **SOCIAL-01** — blokada i obserwowanie mogą współistnieć | `FollowUser` ma wyłącznie sprawdzenie `hasBlockRelationWith()`, bez blokady i bez transakcji; `BlockUser` ma transakcję i `detach` w obie strony. Przeplot zostawia obserwowanie po blokadzie. |
| **MIG-01** — rollback gubi wybór „usuń wszystko" | `down()` zdejmuje `delete_scope`, a ponowne `up()` backfilluje brakujące jako `minimum`. Komentarz migracji twierdzi „żadne dane nie giną" — i to prawda o wierszach, ale **nie o znaczeniu decyzji człowieka**. To ta sama choroba co DB2 z pierwszej warstwy. |

## Wniosek, który wyszedł z trzech warstw naraz

Pierwsza warstwa znalazła bramki operacyjne. Druga i trzecia znalazły
**osiem P1 w samym kodzie i wszystkie są jednym rodzajem błędu**:

> inwariant jest sprawdzany, a potem wykonywany — zamiast być wykonany
> atomowo.

`exists()`, `hasBlockRelationWith()`, `jestMiejsce()`, sprawdzenie ważności
przed transakcją — każde z nich jest poprawne przy jednym żądaniu i każde
przepuszcza drugie. Audyt nazwał to najlepiej w raporcie 21: **rozjazd
między „działa w pojedynczym happy path" a „zachowuje inwariant przy dwóch
requestach, crashu workera i częściowym wykonaniu".**

Dwa wnioski praktyczne, które zostają w projekcie:

1. **Gwarancję daje constraint albo blokada, nie `exists()` w PHP.** `exists()`
   jest dobre na ładny komunikat i tam zostaje.
2. **Blokada bez rewalidacji pod nią nie pilnuje niczego** — serializuje,
   ale nie mówi żądaniu, że świat zmienił się, gdy ono czekało.

Oba stoją w D-079 i obowiązują szerzej niż miejsce, w którym zostały
zapisane.

## Czego z trzeciej warstwy nie sprawdzałem

**MEDIA-01** (sprzątacz osieroconych zdjęć ściga się z przypinaniem),
**MEDIA-03** (autoryzacja jednego zdjęcia to co najmniej pięć zapytań do
bazy, a feed generuje ponad sto żądań obrazków) i P2 z raportów 22–27. Są
wystawione jako issues z cytatami z audytu. MEDIA-03 wymaga **pomiaru liczby
zapytań**, nie oszacowania — repozytorium ma na to gotowy wzorzec
(`DB::flushQueryLog()` + porównanie „mało vs dużo" w istniejących testach
wydajności), i tak trzeba to zrobić, zanim ktokolwiek zaproponuje Redisa.
