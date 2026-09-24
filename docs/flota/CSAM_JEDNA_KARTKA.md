# CSAM — wersja siódma. PIERWSZA Z POMIAREM

21.09.2026. Poprzednie sześć wersji były analizą kodu. **Ta ma wyniki.**
Instrukcja operacyjna, nie porada prawna.

## Odpowiedź na pytanie, o które chodziło przez cały dzień

**Czy po usunięciu treści i banie konta plik jest jeszcze dostępny? TAK, dwiema
drogami — i to są dwie różne sprawy.**

1. **Podpisanym adresem magazynu wydanym wcześniej** — plik jest dostępny przez
   **cały czas ważności podpisu**, niezależnie od usunięcia obu treści i banu.
   Odcina go **wyłącznie zegar**: `200` po każdej decyzji, `403` dopiero po 5 minutach.
2. **Trasą aplikacji dla moderatora** — `302` na **świeży** podpisany adres
   **w każdym stanie**, także po usunięciu obu treści i po banie.
   `DostepDoZdjecia::wlascicielLubModerator()` przepuszcza moderatora **przed**
   odpytaniem rodziców.

Dla **gościa** trasa aplikacji odcina — ale dopiero **na banie**, nie na usunięciu
treści. Po `Usuń treść` na pierwszej treści gość dalej dostawał `302` przez drugiego rodzica.

## Główka pomiaru

| | |
|---|---|
| SHA bazowy | `cd966aae` · pomiar: `62cf279b` na `flota/pomiar-odciecia` (sam test, zero kodu produkcyjnego) |
| Dysk / sterownik | `pomiar_r2`, sterownik **`r2`** (`DyskR2` → `R2Adapter`) |
| Magazyn | lokalne MinIO `RELEASE.2025-09-07`, `127.0.0.1:59310`, path-style |
| Ważność podpisu | **`kuking.media.signed_url_minutes` = 5 min** (odczytane, nie założone) |
| `Cache-Control` | **`public, max-age=150`** — i na `302`, i na odpowiedzi magazynu |
| Materiał | jednolity szary prostokąt 960×720, `imagecreatetruecolor` + `imagewebp` |

## Tabela: stan × pytający × HTTP

| Stan | gość (trasa) | właściciel (trasa) | moderator (trasa) | stary podpis |
|---|---|---|---|---|
| 1. przed czymkolwiek | **302** → magazyn | **302** | **302** | **200** |
| 2. po `Usuń treść` na A | **302** → magazyn | **302** | **302** | **200** |
| 3. po banie autora | **404** | **302 → `/login`** | **302** → magazyn | **200** |
| 4. po usunięciu B | **404** | **302 → `/login`** | **302** → magazyn | **200** |
| 5. po 5 min 20 s | — | — | — | **403** |

**Pułapka, na którą mierzący sam się nadział i dlatego jest osobno:** w stanach 3 i 4
właściciel dostaje `302`, ale **na `/login`** — `User::ban()` woła `invalidateSessions()`.
Pierwszy przebieg policzył to jako „właściciel widzi zdjęcie". Nie widzi; został
wylogowany. **To nie dowodzi, że właściciel zdjęcia jest odcięty w ogóle** — dowodzi
tylko, że **zbanowany** właściciel jest wylogowany.

## Co pomiar potwierdził, a co obalił

| twierdzenie z wcześniejszych wersji | wynik |
|---|---|
| Ban odcina zwykłym odbiorcom dostęp do treści autora | **POTWIERDZONE** — gość `302 → 404` dokładnie na banie |
| Ban nie unieważnia podpisu, który już wyszedł | **POTWIERDZONE** — `200` po banie, `403` dopiero po zegarze |
| Podpis 5 min i cache 150 s to „rachunek, nie pomiar" | **rachunek był prawidłowy, teraz zmierzony** — i te same 150 s wychodzą też z magazynu |
| Kolejność w `MediaController` może wydawać podpis przed sprawdzeniem uprawnień | **OBALONE** — `abort_unless($decyzja->dlaWidza, 404)` stoi **przed** `temporaryUrl()`; odmowa nie ma nagłówka `Location`, więc nie wydaje nowego podpisu |

**Nowe, czego żadna wcześniejsza wersja nie miała: moderator nie jest odcięty w żadnym
stanie**, także po usunięciu obu treści. To wynika z `wlascicielLubModerator()` — reguły
świadomej i skądinąd sensownej, ale przy żądaniu odcięcia **konkretnego pliku** ona
też przepuszcza.

## Osiągalność scenariusza

Zdjęcie o dwóch rodzicach **zbudowano wprost w bazie** (`$wpis->media()->attach()` na
pivocie `post_media`). Zwykłą ścieżką użytkownika się nie dało: `PostController::zebranZdjecia()`
ma `->whereDoesntHave('posts')`, więc formularz nie zaoferuje zdjęcia już przypiętego.

**Okno „usunięto jedną treść, druga trzyma zdjęcie" jest osiągalne po stronie danych,
ale nie znaleziono trasy, którą zwykły użytkownik by je otworzył.** Nie twierdzimy,
że takiej trasy nie ma — twierdzimy, że tą jedną nie.

## Czego pomiar NIE rozstrzyga — i dlaczego

1. **Czy produkcja używa jeszcze `r2_legacy` z publicznym URL-em.** Wymaga odczytu
   zmiennych produkcji. **Pytanie do właściciela, nie wynik.** Jeżeli jest w użyciu,
   powyższa tabela **nie opisuje tej drogi w ogóle** — tam adres jest publiczny
   i bezterminowy.
2. **Czy `cdn.kuking.pl` nadal wskazuje bucket wariantów.** Żyje w panelu Cloudflare,
   nie w repozytorium (issue #120).
3. **Cache brzegowy CDN.** MinIO nie stoi za Cloudflare, więc **nie zmierzono, czy
   i jak długo bajty żyją na brzegu po wygaśnięciu podpisu.** Przy `public, max-age=150`
   to jest realne okno, którego `403` z magazynu **nie zamyka**.
4. **Właściciel zdjęcia na innym, niezbanowanym koncie** — stanu nie zbudowano,
   bo nie znaleziono drogi powstawania pary międzykontowej.
5. **Cache przeglądarki i proxy** — mierzono `curl`-em bez cache.

**MinIO to nie jest Cloudflare R2.** Pomiar szedł przez adapter `r2`, ale przeciw
lokalnemu MinIO — to potwierdza zachowanie **badanego środowiska**, nie usługi R2.
Znana różnica konfiguracji (MinIO adresuje bucket ścieżką, R2 hostem) nie dotyka
samego podpisywania, ale **twierdzenie, że różnica nie dotyka wyniku, byłoby zbyt
kategoryczne bez próby na prawdziwym R2**. Osobno: **odczyt nagłówka `Cache-Control`
nie jest pomiarem działania cache** — mówi, co serwer deklaruje, nie co robi
pośrednik.

## Co moderator robi DZIŚ

1. **Nie kopiuj, nie pobieraj, nie przesyłaj dalej** — także między moderatorami.
2. **`Usuń treść`**. Miękkie usunięcie, dane zostają do zgłoszenia.
   **Wiedz, że samo to nie odcina pliku** — patrz stan 2 w tabeli.
3. Podstawa **„Krzywdzenie dzieci — usuwamy natychmiast"**, pole wiadomości puste.
   **NIE „Treść niezgodna z prawem"** (`required_if` nie przyjmie pustego pola).
   **Powiadomienie i tak nazwie kategorię** — `PodstawaDecyzji.php:267`.
4. **Ban konta to OSOBNA sprawa** (jedno zgłoszenie = jedna decyzja). **W układzie
   zmierzonym** — zdjęcie przy dwóch treściach — to ban odciął gościa, a usunięcie
   pierwszej treści nie. **To nie jest reguła ogólna:** nie zmierzono usunięcia
   jedynej widocznej treści ani usunięcia obu treści przed banem. Traktuj więc ban
   jako czynność odcinającą **tam, gdzie zdjęcie wisi jeszcze gdzie indziej**,
   i rób go bez zwłoki.
5. **Zgłoś**: Dyżurnet.pl, oraz/lub **Policja (997 albo 112)**. Przy zagrożeniu
   suicydalnym **116 123**.
6. **Okno dla adresów wydanych przed decyzją NIE jest "co najmniej 5 minut".**
   Podpis zachowuje **pozostały** czas ważności, liczony **od wystawienia**:
   wydany cztery minuty przed banem daje około minuty. Zależy więc od tego, kiedy
   kto ostatnio otworzył stronę. **Jednego wspólnego końca nie ma** — moderator nie
   jest odcięty i może wydawać kolejne podpisy. Cache brzegowy liczy się osobno
   i pozostaje niezmierzony.
7. **Jeżeli zgłoszenie dotyczy samego zdjęcia albo awatara (cel `media`) — panel
   nie da Ci na nim usunięcia ani ukrycia.** `ModerationAction::DOZWOLONE['media']`
   to `none`, `warn`, `suspend`, `ban`. Ostrzeżenie i zawieszenie są, ale żadne
   nie dotyka pliku. **Eskaluj odcięcie pliku do osoby z dostępem do magazynu.**
8. **Nie uznawaj sprawy za zamkniętą po „Decyzja zapisana".**
9. **Udokumentuj**: numery spraw, czas, adresata, numer referencyjny. Bez kopii materiału.

## Do rozstrzygnięcia przez właściciela

1. **Czy `r2_legacy` jest jeszcze w użyciu na produkcji.** Jedyne pytanie, które
   może wywrócić całą powyższą tabelę.
2. **Czy moderator ma być odcinany od pliku objętego żądaniem odcięcia.** Dziś nie jest.
3. **Czy potrzebne jest odwracalne odcięcie konkretnego pliku**, działające przed
   regułą „którykolwiek rodzic", przed wyjątkiem właściciela i moderatora, **i
   obejmujące adresy już wydane**. Sam `SoftDeletes` tego nie zapewni.
4. **Czy powiadomienie ma nazywać kategorię przy tej podstawie.**
5. **Potwierdzenie u prawnika** obowiązku z art. 18 DSA — playbook §7.1 pkt 3 sam o to prosi.
6. **Osoba odpowiedzialna i zastępca**, imiennie.

## Jak powtórzyć pomiar

```
POMIAR_S3_ENDPOINT=http://127.0.0.1:59310 POMIAR_S3_KEY=… POMIAR_S3_SECRET=… \
  php artisan test --filter PomiarOdcieciaDostepuDoPlikuTest
```
Trwa ~5,5 min, bo czeka na wygaśnięcie podpisu. **Bez `POMIAR_S3_ENDPOINT` test się
POMIJA, a nie przechodzi** — żeby CI bez MinIO nie ogłosiło zielonego bez pomiaru.

Przy okazji uruchomiono `ZdjeciaChronioneNieWyciekajaTest` i
`AutoryzacjaZdjeciaJednymPrzejsciemTest`, o których wcześniejsze wersje pisały, że
nigdy ich nie uruchomiono: **40 przeszło, 222 asercje.**
