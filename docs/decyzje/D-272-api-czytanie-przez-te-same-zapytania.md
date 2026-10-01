## D-272 — API: czytanie przez te same zapytania i Policy co WWW, zdjęcia tylko przez `DostepDoZdjecia` (25 września 2026)

**Data:** 25 września 2026 · Etap 3 API (D-270) · Status: **obowiązuje**

### Co

- `GET /api/v1/feed` — ten sam `FollowingFeed` co strona główna:
  chronologicznie, kursorowo (`?cursor=` z `meta.next_cursor`), te same
  filtry widoczności, blokad, aktywnych autorów i widocznego przepisu.
- `GET /api/v1/wpisy/{uuid}` i `/komentarze` — `PostPolicy::view`.
- `GET /api/v1/przepisy/{uuid}` i `/komentarze` — `RecipePolicy::view`.
  **Po UUID, nie po slugu** jak WWW: slug zmienia się z tytułem, aplikacja
  trzyma identyfikator.
- `GET /api/v1/profile/{username}` — `UserPolicy::viewProfile`.
- Komentarze przez `Comment::scopeWidoczneDla()` — blokady w obie strony.
- **Wątek niesie najwyżej `kuking.api.odpowiedzi_w_watku` (3) najstarszych
  odpowiedzi (#1970)** — limit w SQL na wątek (`ROW_NUMBER()` przez
  `limit()` w ograniczeniu relacji), obok `replies_count` (widoczne dla
  widza) i `more_replies_url`. Dalsze odpowiedzi:
  `GET /api/v1/komentarze/{uuid}/odpowiedzi` — od pierwszej, kursorowo,
  strona `comments.page_size`, bramka `CommentPolicy::view`, dla odpowiedzi
  (nie korzenia) 404. Logika w `App\Domain\Api\WatkiKomentarzy`.
- **Widoczność przepisu na karcie wpisu z feedu rozstrzyga lista, nie
  zasób (#1971).** `Post::ukryjNiedostepnePrzepisy()` (jedno zapytanie na
  stronę, ostrzej niż polityka — bez furtki moderatora) oznacza wpis
  `przepisRozstrzygnietyDla`; `PostResource` pyta `RecipePolicy::view()`
  tylko o wpis spoza takiej listy (`GET /wpisy/{uuid}`). Wcześniej polityka
  szła per wpis (N+1), a przy przepisie ładowanym bez `status`
  i `author_id` odrzucała każdy przepis feedu — API nie pokazywało
  przepisów na kartach wcale. Liczba zapytań feedu jest stała względem
  liczby wpisów (test).
- **Zdjęcia: `GET /api/v1/zdjecia/{uuid}/{wariant}` to ten sam
  `MediaController` co `media.show`** — `DostepDoZdjecia` pyta Policy
  rodzica. Zasoby JSON podają WYŁĄCZNIE adresy tej trasy
  (`App\Http\Resources\Api\V1\Zdjecie`), nigdy klucz w buckecie; zdjęcie bez
  gotowego wariantu nie trafia do odpowiedzi.
- Zasoby (`app/Http/Resources/Api/V1`) nie wypuszczają pól prywatnych:
  adresu e-mail (poza `GET /ja`), statusu moderacji, `klucz_wyslania`,
  `hide_as_memory`, skanu źródła przepisu.
- API nie ma gościa: bez tokenu 401 także tam, gdzie WWW wpuszcza bez
  logowania. Trasy z identyfikatorem są w
  `KazdaTrasaZIdentyfikatoremPodPolicyTest` (pięć ról).

### Czego tu świadomie nie ma

Listy wpisów i przepisów na profilu, „Świeżo z Kuking", wyszukiwarki
i powiadomień — etap 2 aplikacji. Profil w WWW liczy widoczność prywatną
metodą kontrolera (`ProfileController::tylkoWidoczne`); przed dodaniem tej
listy do API trzeba ją najpierw wyciągnąć do domeny (D-014, pkt 2).

📄 `app/Http/Controllers/Api/V1/` · `app/Http/Resources/Api/V1/` ·
`tests/Feature/Api/CzytanieApiTest.php`
