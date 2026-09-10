# Co dalej po audycie z 10.09.2026 — stan na koniec sesji

Ten plik istnieje, żeby następna sesja nie musiała czytać całego audytu od
nowa, i żeby nikt nie scalił PR-a, którego nikt nie sprawdził.

## Zasada, która się w tej sesji obroniła

**Audyt zewnętrzny i PR agenta to HIPOTEZY, nie prawda.** Sprawdzaj przy
pliku, z kontrolą ujemną: zepsuj to, czego test pilnuje, i sprawdź, że test
OBLEWA. W tej sesji ten nawyk złapał:

- nieprawdziwą tezę P0 audytu (pętla zgłaszającego istnieje od #10 —
  `SPRAWDZENIE.md`);
- trzy testy widoczności, które przechodziły także przy wyszukiwarce
  nie zwracającej nikogo (PR #266);
- zdanie „wpisz kod do ręcznego wpisania" w instrukcji, która miała
  zdejmować koszt poznawczy (PR #269).

Druga zasada, która złapała już pięć osób w tym projekcie: **assercja na
całym HTML-u łapie to samo słowo skądinąd.** Sprawdzaj wewnątrz konkretnego
elementu.

## PR-y — stan weryfikacji

| PR | Co | Sprawdzone przeze mnie |
|---|---|---|
| #265 | nazwa konta podpowiadana z imienia | tak, dwie kontrole ujemne |
| #266 | „znasz już kogoś tutaj?" na onboardingu | tak; dołożyłem kontrole dodatnie do testów widoczności |
| #267 | audyt w repo + `SPRAWDZENIE.md` + `OTWARCIE.md` jako snapshot | dokumentacja |
| #268 | landing: „Jak działa" przed tablicą | **scalone**, trzy kontrole ujemne |
| #269 | audyt 60+: focus not obscured, belka `sticky`, teksty | tak; przeczytałem przyczynę usterki i sprawdziłem, że belka zostaje na całą szerokość |
| #253 | awarie poczty (D-062) | wymaga uzgodnienia `HealthController` ze scalonym #255 |
| #256 | identyfikatory formularzy w kolejkach panelu (#243) | do sprawdzenia |
| #264 | research migracji z Garnka | wstrzymane do potwierdzenia faktów o kodzie |
| #213 | kopia bazy offsite | właściciel odłożył |

Gałęzie agentów z tej sesji, których PR-ów mogłem już nie zobaczyć:
`claude/priorytet-w-kolejce-moderacji`, `claude/zaufane-hosty`,
`claude/dowod-zgody-na-digest`, `claude/pwa-orientacja-i-sitemap`,
`claude/dokumentacja-nie-klamie`, `claude/logowanie-kontem-google`.
**Żadnej z nich nie sprawdziłem.** PR z tytułem „SZKIC:" jest niedokończony.

Uwaga do `claude/zaufane-hosty`: `trustHosts` potrafi położyć produkcję przez
odrzucanie własnego healthchecku Railway. Nie scalać bez dowodu, że
healthcheck przechodzi.

## Do wystawienia jako issues (potwierdzone, nieprzypisane)

Z audytu, w kolejności wartości:

1. **S1 (P0/P1)** — bezpośredni origin Railway podważa `X-Forwarded-For`.
   Wymaga Cloudflare (mTLS / origin lock / token krawędziowy), nie kodu.
2. **PERF2 (P1)** — upload do 6 × 15 MB idzie przez proces PHP. Direct-to-R2
   z podpisanym PUT, `incoming/` prywatne, `ready` dopiero po re-encodingu.
3. **CI1 (P1)** — PHPStan level 1 → 2 → 3 → 5 → 8, bez baseline'u, najpierw
   `app/Domain`. „0 błędów" dziś znaczy 0 na poziomie 1.
4. **Supply chain (P1)** — pin actions do pełnego SHA (szczególnie tych
   z sekretami), pin wersji `@railway/cli`, polityka blokowania high/critical
   CVE dla zależności produkcyjnych, lekki cykliczny audit CVE.
5. **PROD3 / 5.6 (P1)** — publiczna strona „Tematy" jako jawne odkrywanie
   (odpowiednik fotofora z Garnka), bez algorytmicznego rankingu.
6. **`.topbar` przy czcionce przeglądarki 200%** — znalezione przy #269
   i świadomie tam nie naprawione: naiwne `scroll-padding-top` psuje poprawkę
   dolnej belki, bo obie wartości w `rem` podwajają się razem.
7. **SEO/PWA-03, -04 (P2)** — próg i segmentacja sitemap przed 50 000 URL;
   unieważnianie 6-godzinnego cache przy zmianie widoczności lub banie.
8. **PERF3, PERF4 (P2)** — jedno dekodowanie obrazu na warianty (po
   benchmarku); paginacja listy blokowanych.
9. **A1 (P2)** — `CollectionController::ostatnioZapisane()` do query objectu
   przy następnej zmianie „Moje", nie osobno.
10. **DB3, copy §3 (P2)** — `DATABASE.md` jako stan bieżący, historia do
    `DECISIONS.md`; rozdzielić w dokumentach prawnych datę zmiany treści od
    daty weryfikacji zgodności ze stanem produkcji.
11. **CI4 (P2)** — filtr joba axe/Lighthouse nie obejmuje `routes/`
    i kontrolerów, które potrafią zmienić wynik strony.
12. **#262** — zbieżność na współdzielonym runnerze (`composer: Text file
    busy`); rozdzielić toolcache per job albo serializować `setup-php`.

## Numery decyzji

Zajęte w tej sesji: D-070 (priorytet w kolejce moderacji), D-071 (zaufane
hosty), D-072 (dowód zgody na digest), D-073 (orientacja PWA i jedna nazwa
„Moje"/„Zeszyt"), D-074 (zawężenie deklarowanej wersji PHP do 8.4).
**Pierwszy wolny: D-075.**

Do nadania przez właściciela: numer dla zmiany dolnej belki z `fixed` na
`sticky` (#269) i dla landingu 3+3 kart (#268), jeśli uzna je za decyzje.

## Po stronie właściciela, w tej kolejności

Pełna tabela jest w `docs/OTWARCIE.md`. Trzy najpilniejsze:

1. **Wyłączyć śledzenie otwarć w panelu EmailLabs** (#204) i sprawdzić surowy
   HTML realnie doręczonej wiadomości. Polityka prywatności mówi, że tego nie
   ma, a jest — to stan sprzeczny z opublikowaną informacją.
2. **`kuking:bramka-r2 --zapis`**, potem `kuking:przenies-zdjecia --dry-run`.
   Stare zdjęcia nadal leżą na wolumenie kontenera.
3. **Kopia bazy poza Railway** (#193), potem ćwiczenie odtworzenia (#9).
   Do wykonania #193 każda utrata bazy jest bezpowrotna.
