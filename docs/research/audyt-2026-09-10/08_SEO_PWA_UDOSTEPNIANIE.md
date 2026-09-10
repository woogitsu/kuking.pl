# Audyt 08 — SEO, PWA i udostępnianie

**Repozytorium:** `woogitsu/kuking.pl`  
**Punkt odniesienia:** `main` @ `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Data audytu:** 2026-09-10

## Ocena

Warstwa SEO jest ponadprzeciętnie dobrze przygotowana jak na etap przed publicznym startem: są canonicale, Open Graph, karta Twitter/X, `Recipe` JSON-LD, breadcrumbs, dynamiczna sitemap i świadome `noindex`. Service worker ma bezpieczną strategię — nie cache'uje prywatnego HTML-u.

Najważniejsza wada nie dotyczy Google, lecz zainstalowanej PWA: manifest wymusza `portrait-primary`, co jest sprzeczne z deklarowanym celem WCAG 2.2 AA, jeśli orientacja nie jest funkcjonalnie niezbędna. Dla grupy 50–75 bardzo realnym scenariuszem jest tablet ustawiony poziomo na podstawce.

## Znaleziska

### SEO/PWA-01 — P1 — PWA wymusza orientację pionową

`public/manifest.webmanifest` zawiera:

```json
"orientation": "portrait-primary"
```

W3C WCAG 2.x, kryterium 1.3.4 Orientation (AA), wymaga aby treść i obsługa nie były ograniczone do jednej orientacji, chyba że konkretna orientacja jest niezbędna. W3C Web App Manifest definiuje `orientation` jako domyślną orientację aplikacji, którą wspierający user agent utrzymuje podczas życia aplikacji.

**Wpływ:**
- użytkownik tabletu zamocowanego poziomo może dostać aplikację obróconą wbrew sposobowi użycia;
- jest to regresja dostępności widoczna dopiero po instalacji PWA, więc zwykły test strony w przeglądarce może jej nie wykryć;
- jest to szczególnie niepotrzebne w Kuking, bo żadna funkcja produktu nie wymaga orientacji pionowej.

**Rekomendacja:** usunąć `orientation` albo ustawić `"orientation": "any"`, a następnie dodać test manifestu pilnujący braku blokady orientacji.

Źródła zewnętrzne:
- W3C WCAG — 1.3.4 Orientation: https://www.w3.org/WAI/standards-guidelines/wcag/new-in-21/
- W3C Web Application Manifest: https://www.w3.org/TR/appmanifest/

### SEO/PWA-02 — P2 — sitemap pomija profile autorów posiadających wyłącznie przepisy

Komentarz `SitemapController` deklaruje, że profil trafia do mapy, jeśli ma co najmniej jedną publiczną treść. Implementacja używa jednak wyłącznie:

```php
->whereHas('user.posts', fn ($query) => $query->publiclyVisible())
```

Nie ma równoważnej ścieżki dla `user.recipes`. Autor, który opublikował kilka przepisów, ale nie dodał zwykłego wpisu, może mieć wartościowy profil publiczny, lecz jego profil nie trafi do sitemap.

**Rekomendacja:** warunek `posts OR recipes`, oba z właściwymi scope'ami widoczności.

### SEO/PWA-03 — P2 — pojedyncza sitemap nie ma twardej ochrony przed limitem 50 000 URL / 50 MB

Kontroler zakłada, że podział na chunki zostanie dodany dopiero „przy dziesiątkach tysięcy adresów”, ale kod nie ma progu ani automatycznej segmentacji. Google ogranicza pojedynczą sitemap do **50 000 URL albo 50 MB nieskompresowane**.

Przy produkcie społecznościowym liczba URL rośnie szybciej niż liczba użytkowników: osobno przepisy, wpisy i profile. Próg powinien być wymuszony kodem, nie pamięcią operatora.

**Rekomendacja:** zanim suma indeksowalnych encji przekroczy ~40–45 tys., wdrożyć sitemap index i osobne mapy np. `recipes`, `posts`, `profiles`, z maks. 40–45 tys. pozycji na plik.

Źródło: Google Search Central, Build and Submit a Sitemap: https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap

### SEO/PWA-04 — P2 — cache sitemap może przez 6 godzin reklamować adres już ukryty

`sitemap.urls` jest cache'owane przez 6 godzin. Zmiana przepisu na prywatny, ban autora lub żądanie usunięcia nie pokazuje tu mechanizmu unieważnienia cache.

Nie jest to wyciek treści — docelowy endpoint nadal musi egzekwować policy — ale przez kilka godzin robot dostaje URL, którego aplikacja już nie chce promować. Przy moderacji i usuwaniu kont jest to niepotrzebne tarcie indeksacyjne.

**Rekomendacja:** tagowany cache / wersjonowanie sitemap i invalidacja przy zmianie widoczności/statusu autora; alternatywnie krótszy TTL do czasu osiągnięcia większej skali.

### SEO/PWA-05 — P2 — manifest używa starego nazewnictwa „Mój zeszyt”, podczas gdy główna nawigacja używa „Moje”

Manifest shortcut:

```json
{
  "name": "Mój zeszyt",
  "short_name": "Zeszyt",
  "url": "/zeszyt"
}
```

Aktualna nawigacja główna pokazuje użytkownikowi `Moje`. Po instalacji aplikacji system operacyjny może więc prezentować inną nazwę tej samej funkcji niż interfejs produktu.

**Rekomendacja:** ustalić jedną nazwę użytkową i użyć jej w UI, PWA i dokumentacji. URL może pozostać `/zeszyt` bez wpływu na copy.

## Co jest zrobione dobrze

- canonical przez `url()->current()` eliminuje parametry zapytania;
- Open Graph ma bezpieczny fallback 1200×630 i nie publikuje nieprzetworzonego oryginału;
- publiczne przepisy dostają `Recipe` JSON-LD i `BreadcrumbList`;
- JSON-LD jest kodowany centralnie i objęty CSP nonce;
- `robots.txt` używa rzeczywistych polskich tras, a `/zglos-nielegalna-tresc` nie jest omyłkowo blokowane;
- service worker nie zapisuje stron HTML zalogowanego użytkownika do Cache Storage;
- cache PWA ograniczono do statycznych `/build/`, `/icons/`, manifestu i strony offline.

## Priorytet wdrożenia

1. **Przed publicznym beta:** usunąć blokadę `portrait-primary`.
2. Naprawić warunek profili w sitemap.
3. Ujednolicić „Moje/Zeszyt”.
4. Dodać automatyczny próg/segmentację sitemap przed wzrostem do dziesiątek tysięcy treści.
