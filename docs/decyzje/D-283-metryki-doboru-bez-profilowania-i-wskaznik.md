## D-283 — Metryki doboru bez profilowania i wskaźnik zastępczy trzeciego progu (#1814, #1781, 26 września 2026)

**Data:** 26 września 2026 · Decyzja właściciela (#1781, pkt 5 z 26.09; kryteria #1814) · Status: **obowiązuje**

### Decyzja

Panel admina **„Metryki doboru”** (`/admin/metryki`, bramka
`UserPolicy::przegladajMetryki` — tylko admin) pokazuje wyłącznie **agregaty
z istniejących tabel** (`posts`, `first_post_events`, `comments`,
`cooked_events`, `post_tags`). Bez nowych zdarzeń, bez `post_id` i nazw osób
w wyniku, bez logu wyświetleń; **ukrycia (D-278) i reakcje „Smakowicie
wygląda” (D-280) nie są źródłem** — strażnik
`MetrykiDoboruTest::test_nie_czyta_ukryc_ani_reakcji` (i dotychczasowy skan
`app/Domain/Analytics` w `UkryjWpisIOsobeTest`). Z liczb wyłączone są konta
z `CookEligibility::excludedUserIds()` (gospodarz, zalążkowe, zamknięte).

| Metryka | Definicja |
|---|---|
| Pierwsze wpisy z odpowiedzią w 24 h | wpisy z `first_post_events` sprzed 1–30 dni; odpowiedź = opublikowany komentarz innej osoby (nie konta zalążkowego) albo „Ugotowałem” przy wskazanym przepisie, najpóźniej 24 h po publikacji |
| Autorzy publikujący ponownie w 28 dni | kohorta: pierwszy wpis 28–56 dni temu; powrót = kolejny opublikowany wpis w 672 h |
| Udział 10% najaktywniejszych | publiczne wpisy z 30 dni; `ceil(10%)` autorów (co najmniej jeden) z największą liczbą **własnych** wpisów |
| Różnych autorów dziennie | publiczne wpisy, dni czasu polskiego, 28 pełnych dni bez dzisiejszego; średnia = głębokość pierwszej rundy Odkrywania |
| Publiczne z tagiem | publiczne wpisy z 30 dni z ≥ 1 aktywnym tagiem (warunek ukrywania tagów: 60%) |
| Tygodnie danych | pełne tygodnie od pierwszego publicznego wpisu społeczności |

### Wskaźnik zastępczy trzeciego progu

Propozycja z #1814 — „> 30% autorów bez pierwszej strony w 7 dni” — wymaga
wiedzy, co kto widział, czyli logu wyświetleń, którego nie prowadzimy. Wybrany
zastępnik liczy się **z samych godzin publikacji**, bo pierwsza strona „Świeżo
z Kuking” jest deterministyczna (D-276): to najnowszy wpis każdej z
`feed.page_size` osób, które publikowały ostatnio. Wpis stoi więc na pierwszej
stronie od publikacji do chwili, gdy po nim opublikuje `page_size` **innych**
osób (albo autor doda nowszy wpis — wtedy stoi nowy). Dla każdego autora
z publicznym wpisem sprzed 1–8 dni sumujemy ten czas (liczony do teraz);
**„praktycznie bez pierwszej strony” = łącznie mniej niż 60 minut**
(`kuking.metryki.minut_na_pierwszej_stronie`). Próg rewizji: > 30%
(`odsetek_bez_pierwszej_strony`).

Dlaczego ten, a nie inny: mierzy dokładnie to, czego próg dotyczy — tłok na
pierwszej stronie przy rosnącej liczbie autorów — nie wymaga żadnej nowej
danej i nie mówi nic o konkretnym widzu. Odrzucone: „wpisy bez odpowiedzi
w 7 dni” (mierzy odzew, nie widoczność, i dubluje pierwszą metrykę) oraz
liczniki z `product_signals` (nie mają `post_id` z założenia). Znane
uproszczenie: pomija bramki per widz (blokady, ukrycia) i zdjęcia moderacyjne
w trakcie — dla progu liczonego w dziesiątkach procent bez znaczenia.

Progi rewizji — dopisek w D-275.

### Wycofanie

Bez migracji: usunąć trasę `admin.metryki`, `MetrykiController`,
`App\Domain\Analytics\MetrykiDoboru`, widok i `kuking.metryki`.

📄 `app/Domain/Analytics/MetrykiDoboru.php` · `app/Http/Controllers/Admin/MetrykiController.php` ·
`tests/Feature/MetrykiDoboruTest.php` · D-275 · D-276 · D-278 · D-280
