# Backlog Kuking.pl

Ten plik jest **mapą**. Praca operacyjna idzie przez
[Issues](https://github.com/matmaxalez/kuking.pl/issues) — tam są opisy,
uzasadnienia i kryteria akceptacji.

Kolejność: `P0` → `P1` → `P2`, w ramach priorytetu według `docs/ROADMAP.md`.

Legenda: ✅ zrobione · 🔨 w repozytorium, wymaga dokończenia · ⬜ do zrobienia

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
