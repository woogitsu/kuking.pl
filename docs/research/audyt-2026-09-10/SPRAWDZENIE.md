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
