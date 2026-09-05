# Backlog Kuking.pl

Ten plik jest **mapą**. Praca operacyjna idzie przez
[Issues](https://github.com/woogitsu/kuking.pl/issues) — tam są opisy,
uzasadnienia i kryteria akceptacji.

Kolejność: `P0` → `P1` → `P2`, w ramach priorytetu według `docs/ROADMAP.md`.

Legenda: ✅ zrobione · 🔨 w repozytorium, wymaga dokończenia · ⬜ do zrobienia

Otwarte issues odpowiadające pozycjom z tej listy:

| # | Priorytet | Temat |
|---|---|---|
| [#1](../../issues/1) | P0 | Kreator przepisu 3-krokowy z autosave |
| [#2](../../issues/2) | P0 | Eksport danych — job budujący paczkę ZIP |
| [#3](../../issues/3) | P0 | Pierwszy deploy: Railway + Cloudflare + R2 |
| [#4](../../issues/4) | P0 | CI bez minut GitHub Actions |
| [#5](../../issues/5) | P0 | Maskotka Garnuś — finalna grafika i zastosowania |
| [#6](../../issues/6) | P0 | Panel gospodarza: wpisy bez odpowiedzi |
| [#7](../../issues/7) | P0 | Analityka i definicja Weekly Active Cooks |
| [#8](../../issues/8) | P0 | Regulamin i polityka po prawniku |
| [#9](../../issues/9) | P0 | Backup i przeprowadzony restore drill |
| [#10](../../issues/10) | P0 | Ścieżka odwołania od decyzji moderacyjnej |
| [#11](../../issues/11) | P0 | Tygodniowy digest e-mail |
| [#12](../../issues/12) | P0 | CSP wymuszona, 2FA dla moderatorów, audit zależności |
| [#13](../../issues/13) | P0 | Wiersze składników i kroków bez przeładowania |
| [#14](../../issues/14) | P0 | Open Graph i przekierowania 301 po zmianie tytułu |
| [#15](../../issues/15) | P0 | Testy z realnymi użytkownikami 50+ |
| [#16](../../issues/16) | P0 | Listy relacji i edycja własnego komentarza |
| [#29](../../issues/29) | P0 | Cold start: pierwszych 20 realnych użytkowników |
| [#30](../../issues/30) | P0 | Przekaz „Twoje przepisy nie zginą” |
| [#33](../../issues/33) | P0 | Sentry, PostHog, uptime i alerty |
| [#17](../../issues/17) | P1 | Ekran „Komuś wyszło” |
| [#18](../../issues/18) | P1 | Temat tygodnia i kalendarz polskiej kuchni |
| [#19](../../issues/19) | P1 | Research repozytoriów → INSPIRATION_DECISIONS |
| [#20](../../issues/20) | P1 | Decyzja: Filament czy własny panel |
| [#21](../../issues/21) | P1 | Decyzje o pakietach: Permission, Pennant, Activitylog |
| [#22](../../issues/22) | P1 | Grupy tematyczne (fotofora) |
| [#23](../../issues/23) | P1 | „Moja wersja” — fork przepisu |
| [#24](../../issues/24) | P1 | Tryb gotowania |
| [#25](../../issues/25) | P1 | Logowanie linkiem e-mail |
| [#26](../../issues/26) | P1 | Automaty dostępności (axe-core, Lighthouse) |
| [#27](../../issues/27) | P1 | Planer posiłków i lista zakupów |
| [#31](../../issues/31) | P1 | Zainteresowania i „Obserwuj temat” w bazie |
| [#32](../../issues/32) | P1 | Larastan/PHPStan działający lokalnie |
| [#34](../../issues/34) | P1 | „Rok temu gotowałaś…” |
| [#35](../../issues/35) | P1 | Web Push (po ustaleniu limitów) |
| [#38](../../issues/38) | P0 | Przepisać teksty interfejsu według COPY_STYLE.md |
| [#39](../../issues/39) | P0 | Zbanowane konto działa do końca sesji |
| [#40](../../issues/40) | P0 | Kara czasowa bez terminu wygaśnięcia |
| [#41](../../issues/41) | P1 | Testy widoczności: stan × typ obserwatora |
| [#42](../../issues/42) | P1 | Lista zastrzeżonych nazw użytkownika |
| [#43](../../issues/43) | P1 | Brakujące ograniczenia `UNIQUE` |
| [#28](../../issues/28) | P2 | OCR zeszytów i import z adresu strony |
| [#36](../../issues/36) | P2 | Monetyzacja — co realnie sprzedać |
| [#44](../../issues/44) | P2 | `recipe_ingredients.no_amount` |

---

## P0 — fundament

- ✅ szkielet Laravel 13 + PHP 8.4 + Livewire 4 + Tailwind 4
- ✅ PostgreSQL: pełny schemat MVP w migracjach, z `CHECK`-ami i indeksami częściowymi
- ✅ konto, logowanie (e-mail **albo** nazwa użytkownika), reset hasła, weryfikacja e-maila
- ✅ profil publiczny `/@nazwa` z archiwum po miesiącach
- ✅ ustawienia czytelności — rozmiar tekstu zapisywany **na koncie**
- ✅ GitHub Actions: pint, analiza statyczna, testy na PostgreSQL 18, build assetów
- ✅ `/health` sprawdzający realnie bazę
- ✅ Railway IaC + produkcyjny `Dockerfile` (FrankenPHP)
- ⬜ pierwszy deploy na Railway i podpięcie domeny w Cloudflare
- ⬜ Sentry i PostHog podłączone na staging

## P0 — społeczność

- ✅ obserwowanie i przestanie obserwowania
- ✅ blokowanie (kasuje obserwowanie w obie strony, działa symetrycznie)
- ✅ feed obserwowanych — chronologiczny, kursorowy
- ✅ „Świeżo z Kuking” przy pustym feedzie + propozycje osób
- ✅ powiadomienia w aplikacji
- ⬜ tygodniowy digest e-mail
- ⬜ ekran „Kto Cię obserwuje” i lista obserwowanych

## P0 — treść

- ✅ upload zdjęć: magic bytes, limit megapikseli, re-enkodowanie zdejmujące EXIF/GPS
- ✅ warianty zdjęć thumb/feed/large
- ✅ wpis: zdjęcie + kilka słów, z wyborem widoczności
- ✅ archiwum profilu pogrupowane po miesiącach
- ✅ przepis: szkic i publikacja, sekcja „Skąd ten przepis”, „po kim”, „w rodzinie od”
- ✅ wersje przepisu (`recipe_versions`) zapisywane przy publikacji
- ✅ **„Ugotowałem”** z powiadomieniem autora
- ✅ komentarze i odpowiedzi (jeden poziom)
- ✅ zeszyt (kolekcje) z domyślnym zeszytem tworzonym przy pierwszym zapisie
- ✅ wyszukiwarka: przepisy, składniki, ludzie — `pg_trgm` + `unaccent`
- 🔨 kreator przepisu jako **trzykrokowy z autosave** (dziś: jedna strona, bez autosave)
- ⬜ dodawanie i usuwanie wierszy składników bez przeładowania strony
- ⬜ edycja i usuwanie własnego komentarza z poziomu interfejsu

## P0 — zaufanie i bezpieczeństwo

- ✅ zgłaszanie treści (przycisk z napisem „Zgłoś”, powody po polsku)
- ✅ kolejka moderacji z decyzją, uzasadnieniem i wiadomością do użytkownika
- ✅ dziennik audytu
- ✅ nagłówki bezpieczeństwa, limity zapytań per endpoint
- ✅ usunięcie konta z 30-dniowym okresem na zmianę zdania
- 🔨 eksport danych — jest kolejka i model, brakuje joba budującego paczkę ZIP
- ⬜ ścieżka odwołania od decyzji moderacyjnej (widok dla użytkownika)
- ⬜ sprawdzanie statusu konta przy **każdym** żądaniu (dziś ban działa dopiero po wylogowaniu)
- ⬜ `users.status_expires_at` — bez tego każda kara czasowa jest dożywotnia
- ⬜ CSP w trybie wymuszającym (dziś: Report-Only)
- ⬜ 2FA dla kont moderatorów i administratorów
- ⬜ backup bazy + **przeprowadzony** restore drill
- ⬜ regulamin i polityka prywatności po weryfikacji prawnika

## P0 — web i SEO

- ✅ publiczne profile i przepisy, JSON-LD `Recipe`, `ProfilePage`, `BreadcrumbList`
- ✅ sitemap, robots, `noindex` na stronach prywatnych i wyszukiwarce
- ✅ PWA: manifest, service worker, strona offline, ikony (Garnuś)
- ⬜ karty Open Graph ze zdjęciem dania
- ⬜ przekierowania 301 po zmianie tytułu przepisu (tabela już jest)

---

## P1 — po potwierdzeniu retencji

- ⬜ grupy tematyczne / fotofora
- ⬜ „Moja wersja” — fork przepisu z zachowaniem autorstwa oryginału
- ⬜ tryb gotowania (duży tekst, ekran nie gaśnie, krok po kroku)
- ⬜ timery w krokach przepisu
- ⬜ rodzinna książka kucharska
- ⬜ pytania do autora przepisu jako osobny typ interakcji
- ⬜ Web Push
- ⬜ temat tygodnia jako dane redakcyjne
- ⬜ planer posiłków
- ⬜ lista zakupów

## P2 — dalej

- ⬜ OCR starych zeszytów
- ⬜ import przepisu z adresu strony
- ⬜ spiżarnia i „co ugotuję z tego, co mam”
- ⬜ zamienniki składników i skalowanie porcji
- ⬜ wartości odżywcze
- ⬜ decyzja o aplikacjach natywnych — dopiero gdy PWA potwierdzi retencję

---

## Bramki (nie przechodzimy dalej, dopóki nie są spełnione)

**Zamknięta alfa:** 20+ realnych użytkowników · stabilny upload zdjęć ·
brak blokerów UX w testach z osobami 50-70+ · działająca moderacja ·
**przetestowany** restore bazy.

**Publiczny start:** regulamin i polityka po prawniku · procedura zgłoszeń
zgodna z DSA · CSP wymuszona · 2FA dla adminów · monitoring z alertami.

**V1 (planer, grupy, forki):** dopiero gdy Weekly Active Cooks i D30
pokazują, że ludzie wracają.
