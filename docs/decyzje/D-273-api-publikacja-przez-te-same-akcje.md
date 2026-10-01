## D-273 — API: publikacja przez te same Akcje co WWW — wpis, „Ugotowałem", komentarz, obserwowanie (25 września 2026)

**Data:** 25 września 2026 · Etap 4 API (D-270) · Status: **obowiązuje**

### Co

- **`POST /api/v1/wpisy`** (multipart: `photos[]`, `body`, `visibility`,
  opcjonalnie `tags[]`) — główna akcja „Co dziś ugotowałeś?". Zdjęcia przez
  `StoreUploadedImage` (te same limity typów i rozmiaru z `LimityZdjec`,
  magic bytes, limit megapikseli, re-enkodowanie w tle zdejmujące EXIF/GPS),
  wpis przez `PublishPost`. Nagłówek `Idempotency-Key` (UUID) pełni rolę
  ukrytego `klucz_wyslania` z formularza: ponowienie przy słabym zasięgu nie
  tworzy drugiego wpisu (201 za pierwszym razem, 200 przy powtórce).
- **`POST /api/v1/przepisy/{uuid}/ugotowalem`** — `RecordCookedEvent` pod
  `RecipePolicy::cook`. Powiadomienie autora robi ta sama akcja
  (`NotifyUser`), więc trzy granice z AGENTS.md §1 obowiązują bez kopii:
  własny przepis, konto autora zamknięte, blokada. Zmierzone w
  `tests/Feature/Api/PublikacjaApiTest.php`.
- **`POST /api/v1/wpisy/{uuid}/komentarze`** (`PostPolicy::comment`)
  i **`POST /api/v1/przepisy/{uuid}/komentarze`** (`RecipePolicy::view`, jak
  WWW) — `PublishComment`; rodzic odpowiedzi szukany tylko wśród komentarzy
  widocznych dla piszącego.
- **`POST`/`DELETE /api/v1/osoby/{uuid}/obserwuj`** — `FollowUser` /
  `UnfollowUser` pod `UserPolicy::follow`/`unfollow`. **Po UUID osoby, nie
  po nazwie** — nazwa może przejść na inne konto (#793).
- Limity: te same prefiksy i progi co formularze WWW (`post`, `comment`,
  `obserwowanie`) — wspólne wiadra. Zawieszone konto: 403
  `konto_zawieszone` z `EnsureApiAccountIsActive`.
- Błędy akcji domenowych (`BladDlaCzlowieka`) wracają jako 422 przy polu,
  tym samym zdaniem co na WWW.

**Czego tu nie ma:** edycji i usuwania wpisów, przepisów z aplikacji,
zeszytów, blokowania, zgłoszeń — etap 2 aplikacji. Zgłaszanie treści
(DSA art. 16) musi wejść do API **przed** publicznym wydaniem aplikacji, bo
publikacja bez drogi zgłoszenia to luka prawna, nie funkcja do dołożenia.

📄 `app/Http/Controllers/Api/V1/PublikacjaController.php` ·
`app/Http/Controllers/Api/V1/UgotowalemController.php` ·
`app/Http/Controllers/Api/V1/KomentarzController.php` ·
`app/Http/Controllers/Api/V1/ObserwowanieController.php` ·
`app/Http/Controllers/Api/V1/Concerns/PrzyjmujeZdjecia.php`
