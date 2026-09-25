# API Kuking — wersja 1

Publiczne API dla aplikacji mobilnej Kuking (decyzje D-014, D-270 … D-273
w `docs/DECISIONS.md`). To **drugi adapter nad tymi samymi Akcjami i Policy**
co strony WWW — nie osobny serwis. Każda reguła domenowa (widoczność,
blokady, limity, „Ugotowałem powiadamia autora") działa tu tak samo, bo
wykonuje ją ten sam kod.

> **Stan: API jest domyślnie WYŁĄCZONE.** Dopóki na serwerze nie ma
> `KUKING_API_ENABLED=true`, każdy adres pod `/api/*` odpowiada 404 — także
> ten, który istnieje. Otwarcie jest decyzją wdrożeniową właściciela.

Ten plik pilnuje test `tests/Feature/Api/ApiJestUdokumentowaneTest.php`:
każda trasa z `routes/api.php` musi mieć tu swój wiersz w tabeli
„Lista endpointów", a każda poza logowaniem — `auth:sanctum`.

---

## 1. Adres i wersjonowanie

Wszystkie endpointy leżą pod prefiksem `api/v1` na domenie serwisu, np.
`https://kuking.pl/api/v1/feed`.

- **Wersja jest w adresie**, nie w nagłówku. Zmiana niezgodna wstecz to nowy
  prefiks (`api/v2`) obok starego, a nie przeróbka `v1` — aplikacji
  w telefonach nie da się zaktualizować w dniu wdrożenia.
- Zgodne wstecz jest: **dodanie** pola do odpowiedzi, dodanie endpointu,
  dodanie opcjonalnego parametru. Aplikacja ma ignorować pola, których nie
  zna.
- Niezgodne wstecz (wymaga `v2`): usunięcie albo zmiana znaczenia pola,
  zmiana kodu odpowiedzi, nowy wymagany parametr.
- Klucze JSON są po angielsku (jak kolumny w bazie), treści dla człowieka
  (`message`, komunikaty walidacji) — po polsku.
- Identyfikatory to UUID. Czas w ISO 8601 z przesunięciem strefy.

## 2. Uwierzytelnianie

Tokeny osobistego dostępu Laravel Sanctum w nagłówku:

```http
Authorization: Bearer 01J9…|kuking_Xy7…
Accept: application/json
```

- Sesja i ciasteczka z przeglądarki **nie otwierają API** — tylko token.
- Token nie ma terminu ważności. Znika, gdy: człowiek wyloguje się
  w aplikacji, odetnie urządzenie na WWW (**Ustawienia → Urządzenia
  z dostępem**), zmieni lub zresetuje hasło, kliknie „Wyloguj mnie z innych
  urządzeń", włączy 2FA; albo gdy konto zostanie zablokowane, zawieszone
  lub zgłoszone do usunięcia.
- Na jedno konto przypada najwyżej 10 urządzeń; kolejne logowanie odcina
  urządzenie używane najdawniej.
- Token trzymaj w bezpiecznym magazynie systemu (Keychain / Keystore), nigdy
  w logach ani w zgłoszeniach błędów. Przedrostek `kuking_` pozwala
  skanerom sekretów rozpoznać token wklejony przez pomyłkę.

### Logowanie bez 2FA

```http
POST /api/v1/tokeny
Content-Type: application/json

{"login": "basia@example.com", "password": "…", "device_name": "Samsung Basi"}
```

Odpowiedź `201`:

```json
{
  "token": "01J9…|kuking_Xy7…",
  "token_type": "Bearer",
  "device": {"id": "01J9…", "name": "Samsung Basi", "created_at": "2026-09-25T12:00:00+00:00"},
  "user": {"id": "…", "username": "basia", "display_name": "Basia", "email": "basia@example.com",
           "email_verified": true, "status": "active", "suspended_until": null, "two_factor_enabled": false}
}
```

`login` to adres e-mail albo nazwa użytkownika — tak jak na WWW.

### Logowanie z 2FA (dwa kroki)

Jeśli konto ma weryfikację dwuetapową, pierwszy krok **nie wydaje tokenu**.
Odpowiedź `202`:

```json
{
  "two_factor_required": true,
  "challenge": "eyJpdiI6…",
  "expires_at": "2026-09-25T12:10:00+00:00",
  "message": "Wpisz sześciocyfrowy kod z aplikacji do kodów albo jeden z kodów zapasowych."
}
```

Drugi krok (w ciągu 10 minut):

```http
POST /api/v1/tokeny/kod
Content-Type: application/json

{"challenge": "eyJpdiI6…", "code": "123456"}
```

albo z kodem zapasowym: `{"challenge": "…", "backup_code": "ABCD-1234"}`.
Odpowiedź `201` jak przy logowaniu bez 2FA. Wyzwanie przestaje działać po
terminie, po zmianie hasła i po każdej zmianie stanu konta — wtedy `422`
przy polu `challenge` i trzeba zacząć od hasła.

### Wylogowanie

```http
DELETE /api/v1/tokeny/biezacy
```

`204` — odwołany jest tylko token, którym przyszło żądanie. Działa także na
koncie zawieszonym.

### Konto zawieszone i zamknięte

| Stan konta | Czytanie | Zapis | Odpowiedź na zapis |
|---|---|---|---|
| aktywne | tak | tak | — |
| zawieszone | tak | nie (poza wylogowaniem) | `403`, `code: konto_zawieszone`, zdanie z terminem kary |
| zablokowane / do usunięcia / usunięte | nie | nie | `401`, `code: konto_zamkniete`; token zostaje skasowany |

## 3. Format błędów

Każdy błąd ma ten sam kształt:

```json
{"message": "Zdanie po polsku, mówiące co zrobić.", "code": "nie_znaleziono"}
```

`message` można pokazać człowiekowi wprost. `code` jest stały i służy
programowi. Przy `422` dochodzi `errors` — pole → lista komunikatów, tych
samych, które widzi formularz na WWW (`lang/pl/validation.php`):

```json
{
  "message": "Popraw zaznaczone pola i wyślij jeszcze raz.",
  "code": "bledne_dane",
  "errors": {"visibility": ["Zaznacz, kto ma widzieć ten wpis."]}
}
```

| HTTP | `code` | Kiedy |
|---|---|---|
| 401 | `brak_logowania` | brak tokenu, token odwołany albo niepoprawny |
| 401 | `konto_zamkniete` | konto zablokowane, do usunięcia albo usunięte |
| 403 | `brak_dostepu` | Policy odmówiła (treść prywatna, blokada); czasem z własnym zdaniem Policy |
| 403 | `konto_zawieszone` | zapis z konta zawieszonego |
| 404 | `nie_znaleziono` | nie ma takiego obiektu albo **API jest wyłączone** |
| 405 | `zla_metoda` | zła metoda HTTP pod tym adresem |
| 413 | `za_duze` | żądanie za duże dla serwera |
| 422 | `bledne_dane` | walidacja — szczegóły w `errors` |
| 422 | `odmowa` | reguła domenowa powiedziała „nie" (np. „Nie można obserwować samego siebie.") |
| 429 | `za_duzo_prob` | limit żądań — nagłówek `Retry-After` mówi, ile sekund czekać |
| 500 | `blad_serwera` | awaria po stronie serwera; treść wyjątku nigdy nie wychodzi |
| 503 | `przerwa` | przerwa techniczna |

Odmowa i brak obiektu bywają celowo nieodróżnialne (`404` zamiast `403`) —
tak samo jak na WWW, żeby nie zdradzać, że coś istnieje.

## 4. Limity żądań

Liczby żyją w `config/kuking.php` (`api.limity` i `limits`), nie w trasach.

| Limit | Wartość | Liczony po |
|---|---|---|
| każde żądanie pod `/api/*` | 300 na minutę | adresie IP (także bez tokenu i z fałszywym tokenem) |
| każde żądanie z tokenem | 120 na minutę | tokenie — dwa telefony to dwa budżety |
| logowanie (`POST /api/v1/tokeny`) | 5 na minutę + trzy koszyki prób hasła | adresie IP, parze login+IP, koncie — **wspólne z formularzem WWW** |
| kod 2FA | 5 na minutę + limit prób na konto | jak wyżej, wspólne z WWW |
| publikacja wpisu, „Ugotowałem" | jak formularze WWW (`post`) | koncie |
| komentarz | jak WWW (`comment`) | koncie |
| obserwowanie | jak WWW (`obserwowanie`) | koncie |
| zdjęcia | jak WWW (`zdjecie`) | koncie |

Po przekroczeniu: `429`, nagłówek `Retry-After`. Aplikacja ma odczekać, nie
ponawiać w pętli.

## 5. Zdjęcia

Zasoby JSON podają zdjęcie tak:

```json
{"id": "01J9…", "alt": "Pierogi na talerzu", "warianty": {
  "thumb": "https://kuking.pl/api/v1/zdjecia/01J9…/thumb",
  "feed": "https://kuking.pl/api/v1/zdjecia/01J9…/feed",
  "large": "https://kuking.pl/api/v1/zdjecia/01J9…/large"}}
```

- Adres wymaga tokenu (`Authorization`) i przechodzi przez tę samą bramkę co
  na WWW (`DostepDoZdjecia` — Policy wpisu, przepisu albo profilu). Zwykle
  odpowiada `302` na krótko ważny podpisany adres pliku; nie zapisuj go na
  stałe, zapisuj adres z API.
- Zdjęcie, które się jeszcze przetwarza, nie pojawia się w odpowiedzi wcale.
- Serwer nigdy nie oddaje pliku w postaci, w jakiej przyszedł: każde zdjęcie
  jest dekodowane i zapisywane od nowa, co zdejmuje EXIF i współrzędne GPS.

Wysyłanie: `multipart/form-data`, pole `photos[]`, formaty i rozmiar jak na
WWW (JPG, PNG, WebP; limit w `config/kuking.php` → `media`).

## 6. Stronicowanie

- **Feed** — kursorowe, chronologiczne (bez algorytmu): kolejną stronę daje
  `?cursor=` z `meta.next_cursor`; `null` znaczy koniec.
- **Komentarze** — numerowane: `?page=2`, liczby w `meta`.

## 7. Lista endpointów

| Metoda i adres | Po co | Uwierzytelnianie | Bramka |
|---|---|---|---|
| `POST /api/v1/tokeny` | logowanie: login + hasło → token albo wyzwanie 2FA | bez tokenu | limity hasła wspólne z WWW |
| `POST /api/v1/tokeny/kod` | drugi krok 2FA → token | bez tokenu | limit prób kodu na konto |
| `DELETE /api/v1/tokeny/biezacy` | wylogowanie tego urządzenia | token | tylko własny token |
| `GET /api/v1/ja` | własne konto (jedyne miejsce z adresem e-mail) | token | — |
| `GET /api/v1/feed` | wpisy obserwowanych, chronologicznie | token | to samo zapytanie co strona główna |
| `GET /api/v1/wpisy/{post}` | wpis | token | `PostPolicy::view` |
| `GET /api/v1/wpisy/{post}/komentarze` | komentarze wpisu | token | `PostPolicy::view` + blokady |
| `POST /api/v1/wpisy` | „Co dziś ugotowałeś?" — zdjęcie + kilka słów | token | konto aktywne |
| `POST /api/v1/wpisy/{post}/komentarze` | komentarz pod wpisem | token | `PostPolicy::comment` |
| `GET /api/v1/przepisy/{przepis}` | przepis (po UUID) | token | `RecipePolicy::view` |
| `GET /api/v1/przepisy/{przepis}/komentarze` | komentarze przepisu | token | `RecipePolicy::view` + blokady |
| `POST /api/v1/przepisy/{przepis}/komentarze` | komentarz pod przepisem | token | `RecipePolicy::view` |
| `POST /api/v1/przepisy/{przepis}/ugotowalem` | „Ugotowałem" — powiadamia autora | token | `RecipePolicy::cook` |
| `GET /api/v1/profile/{username}` | profil osoby | token | `UserPolicy::viewProfile` |
| `POST /api/v1/osoby/{osoba}/obserwuj` | obserwuj | token | `UserPolicy::follow` |
| `DELETE /api/v1/osoby/{osoba}/obserwuj` | przestań obserwować | token | `UserPolicy::unfollow` |
| `GET /api/v1/zdjecia/{media}/{wariant}` | plik zdjęcia (wariant `thumb`, `feed`, `large`, `podglad`) | token | `DostepDoZdjecia` |

### Przykłady

**Feed:**

```http
GET /api/v1/feed
```

```json
{
  "data": [{
    "id": "01J9…", "kind": "dish", "title": null, "body": "Dziś pierogi ruskie.",
    "visibility": "public", "published_at": "2026-09-25T11:40:00+00:00",
    "author": {"id": "…", "username": "basia", "display_name": "Basia", "avatar": null},
    "photos": [{"id": "…", "alt": null, "warianty": {"feed": "https://kuking.pl/api/v1/zdjecia/…/feed"}}],
    "recipe": null, "tags": [{"slug": "pierogi", "name": "pierogi"}],
    "comments_count": 3, "url": "https://kuking.pl/wpisy/01J9…"
  }],
  "links": {"prev": null, "next": "https://kuking.pl/api/v1/feed?cursor=eyJ…"},
  "meta": {"path": "https://kuking.pl/api/v1/feed", "per_page": 20, "next_cursor": "eyJ…", "prev_cursor": null}
}
```

**Publikacja wpisu:**

```http
POST /api/v1/wpisy
Content-Type: multipart/form-data
Idempotency-Key: 5f0c2b8e-7c1d-4d8e-9a57-2b1f1f0b9c11

photos[]=<plik>  body=Dziś pierogi ruskie.  visibility=public  tags[]=pierogi
```

`201` z wpisem (kształt jak w feedzie). Nagłówek `Idempotency-Key` (UUID,
nowy dla każdego wpisu) sprawia, że ponowienie tego samego wysłania przy
słabym zasięgu zwraca `200` z tym samym wpisem, zamiast tworzyć drugi.
`visibility`: `public`, `followers` albo `private`.

**„Ugotowałem":**

```http
POST /api/v1/przepisy/01J9…/ugotowalem
Content-Type: application/json

{"note": "Dałam więcej koperku.", "would_make_again": true, "actual_minutes": 90}
```

`201`:

```json
{"data": {"id": "…", "recipe_id": "01J9…", "note": "Dałam więcej koperku.", "cooked_at": "…"},
 "message": "Wykonanie zapisane."}
```

Autor przepisu dostaje powiadomienie — z trzema wyjątkami opisanymi
w `AGENTS.md` §1 (własny przepis, konto autora zamknięte, blokada).
Opcjonalnie `photos[]`, `changes_note`, `perceived_difficulty`
(`easy`/`medium`/`hard`).

**Komentarz:** `{"body": "Smacznie wygląda!", "parent_id": null}` → `201`
z komentarzem. `parent_id` to odpowiedź na widoczny komentarz.

**Obserwowanie:** `POST` → `201` (`200`, jeśli już obserwujesz),
`{"data": {"following": true}}`; `DELETE` → `200`,
`{"data": {"following": false}}`.

## 8. Czego API jeszcze nie ma

Rejestracji, resetu hasła, powiadomień, wyszukiwarki, list wpisów na
profilu, przepisów (dodawanie i edycja), zeszytów, zgłoszeń treści,
blokowania i usuwania konta. Plan: `docs/DECISIONS.md`, D-273 i
podsumowanie etapu 1 API.
