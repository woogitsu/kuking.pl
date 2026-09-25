# SEO techniczne — Kuking.pl

Stan wiedzy zweryfikowany we wrześniu 2026 (Google Search Central, web.dev). Zasada nadrzędna z `SEO_ANALYTICS_GROWTH.md`: **SEO = akwizycja, nie model biznesowy**. Nic w tym dokumencie nie zakłada masowej produkcji treści pod Google — chodzi wyłącznie o to, żeby prawdziwe treści realnych ludzi (przepisy, wpisy, profile) były poprawnie odczytywalne przez wyszukiwarki i nie szkodziły reputacji domeny.

---

## 1. Struktura URL-i i kanonikalizacja

### 1.1 Slug przepisu

Format: `/przepisy/{slug}`, gdzie `slug` pochodzi z `recipes.slug varchar(220) UNIQUE` (patrz `database/reference/schema_mvp.sql`).

**Transliteracja polskich znaków — tak, zawsze.** Google radzi sobie z UTF-8 w URL-ach, ale dla użytkowników 50+ udostępniających linki przez Messengera/SMS-y ASCII-slug jest bardziej niezawodny (mniej problemów z kopiowaniem, skracaniem linków, starszymi klientami mailowymi) i unika dwuznaczności `ł`→`l`/`w` w różnych transliteratorach.

Reguła transliteracji (Laravel `Str::slug()` z dodaną mapą PL):

```php
// app/Domain/Recipes/Support/RecipeSlugger.php
final class RecipeSlugger
{
    private const array PL_MAP = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
        'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
    ];

    public static function make(string $title, string $recipeId): string
    {
        $base = strtr($title, self::PL_MAP);
        $base = Str::slug($base, '-'); // ascii, lowercase, myślniki
        $base = Str::limit($base, 180, '');

        return $base !== '' ? $base : 'przepis-'.substr($recipeId, 0, 8);
    }
}
```

Przykład: „Żurek na zakwasie z jajkiem” → `zurek-na-zakwasie-z-jajkiem`.

**Unikalność i kolizje.** Kolumna ma `UNIQUE`, więc przy duplikacie tytułu dopisz krótki sufiks: `zurek-na-zakwasie-z-jajkiem-a3f9` (ostatnie 4 znaki z `id`, nie licznik `-2`, `-3` — licznik zdradza istnienie duplikatów i jest podatny na race condition przy równoległych publikacjach).

**Historia slugów i przekierowania 301.** Autor może zmienić tytuł przepisu po publikacji (edycja jest dozwolona w MVP — `recipe_versions` przechowuje historię). Zmiana tytułu nie może psuć już zaindeksowanego i rozesłanego linku. Potrzebna tabela:

```sql
CREATE TABLE recipe_slug_redirects (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    recipe_id uuid NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
    old_slug varchar(220) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (old_slug)
);
```

Logika w use case `UpdateRecipe`: przy zmianie `recipes.slug` zapisz stary slug do `recipe_slug_redirects` **zanim** nadpiszesz kolumnę. Middleware/route fallback: gdy `/przepisy/{slug}` nie znajdzie rekordu w `recipes`, sprawdź `recipe_slug_redirects` i zwróć **301** (permanentne, nie 302) na aktualny URL. 301 przenosi sygnały rankingowe i jest tym, czego oczekuje Googlebot przy trwałej zmianie adresu.

Nie generuj nowego slugu przy każdej drobnej edycji treści (składniki, kroki) — tylko przy zmianie samego tytułu, i najlepiej z potwierdzeniem UI („Zmiana tytułu zmieni adres przepisu — stary link nadal będzie działać”).

### 1.2 Paginacja

Listy (profil — zakładki „Wszystko/Przepisy/Ugotowane”, `/odkryj`, wyniki `/szukaj`) używają **cursor pagination** (zgodnie z `ARCHITECTURE.md`: `ORDER BY published_at DESC, id DESC`), nie numerów stron w stylu `?page=2`. Konsekwencje SEO:

- Jeśli mimo to publiczne strony 2+ mają URL-e (np. `/@basia?page=2`), oznacz je `rel="next"`/`rel="prev"` **nie jest już wspierane przez Google od 2019** — zamiast tego po prostu upewnij się, że strona 1 (kanoniczna, indeksowalna) linkuje do kolejnych stron zwykłymi linkami `<a href>`, żeby crawler mógł je odkryć, i że każda strona ma unikalny, opisowy `<title>`.
- Strony 2+ list nie potrzebują unikalnej wartości SEO — mogą zostać `index,follow` (są prawdziwą treścią, tylko podzieloną), ale **nie kanonikalizuj ich do strony 1** (to ukryłoby treść stron 2+ przed Google, czyli realne przepisy autora by „zniknęły”).
- Implementacja: `App\Support\KanonicznyAdresStrony` (issue #963) buduje `canonical` i `og:url` z białej listy parametrów danej trasy (`page`, `cursor`, `zakladka`, `rok`, `filtr`, `tag`) w stałej kolejności; wartości ignorowane przez kontroler, `utm_*` i parametry nieznane odpadają. Nowa publiczna lista z paginacją lub zakładkami musi dopisać tam swoją trasę.
- Host i pisownia (issues #1311, #1369): korzeń `canonical` i `og:url` pochodzi z `APP_URL`, nie z żądania — wejście przez `www.kuking.pl` bez reguły Cloudflare wskazuje apex, jak sitemapa. Ten sam korzeń (`App\Support\AdresKanoniczny::korzen()`) mają linki „Podziel się” (`Udostepnianie::adres()`), więc sitemapa, canonical i udostępnienia wskazują jeden host. Profil otwarty w innej wielkości liter (`/@basia_1971`) odpowiada 200, ale jego canonical wskazuje zapisaną pisownię (`/@Basia_1971`); celowo bez 301, żeby stare linki działały bez dodatkowego skoku.

### 1.3 Filtry wyszukiwania → `noindex`

`/szukaj?q=...&category=...` — nieskończona liczba kombinacji parametrów zapytania to klasyczny wektor **cienkich, duplikowanych stron** (crawl budget waste, potencjalnie „doorway pages”). Zasada:

- `/szukaj` bez parametrów lub z pustym wynikiem: `noindex, follow`.
- `/szukaj?q=...`: zawsze `noindex, follow` — to strona wyników, nie strona docelowa. Prawdziwa treść (przepis, profil) ma **swój własny kanoniczny URL**, do którego wynik wyszukiwania linkuje.
- Nigdy nie polegaj wyłącznie na `robots.txt Disallow: /szukaj` — to blokuje crawlowanie, ale nie gwarantuje deindeksacji linków już odkrytych skądinąd. Użyj **meta robots / `X-Robots-Tag: noindex`** na samej stronie (patrz sekcja 3), a `robots.txt` potraktuj jako dodatkową oszczędność crawl budgetu.

### 1.4 Duplikaty treści

**Ten sam przepis w wielu wersjach.** `recipes.source_type` (`own | family | adaptation | external`) i `recipe_versions` (historia edycji tego samego `recipe_id`) to nie jest duplikacja w sensie SEO — to jedna encja z historią, jeden URL, jeden kanoniczny adres. Nie generuj osobnych publicznych URL-i per wersja historyczna; historia jest widoczna wewnątrz strony przepisu (np. link „zobacz poprzednią wersję”), a Google widzi tylko bieżący stan pod `<link rel="canonical">`.

**„Moja wersja” (fork przepisu, funkcja V1).** To jest realne ryzyko farmy duplikatów, jeśli zostanie zaimplementowane nieostrożnie: 50 osób robi „swoją wersję” tego samego przepisu na bigos, każda dostaje osobny publiczny URL z niemal identycznym `recipeIngredient`/`recipeInstructions` → setki niemal identycznych stron `Recipe` w indeksie. To pasuje wprost do wzorca, który Google opisuje jako **scaled content abuse** (nawet bez AI — „duże wolumeny bardzo podobnych treści bez dodanej wartości”, patrz sekcja 7).

Zasady procedury dla „Moja wersja” (do wdrożenia razem z funkcją w V1, nie później):
1. Fork domyślnie dziedziczy **niższą widoczność** niż oryginał, dopóki autor forka nie doda realnej zmiany (inny czas, inne proporcje, inna technika — nie tylko inne zdjęcie).
2. Strona forka ma `<link rel="canonical">` wskazujący **na siebie** tylko jeśli różni się od oryginału na tyle, by mieć samodzielną wartość; w przeciwnym razie renderuj ją jako sekcję/wariant na stronie oryginału (`/przepisy/{oryginal-slug}#wersja-{id}`), a nie osobny indeksowalny URL.
3. Widoczny, człowiekowi czytelny link „na podstawie: [Bigos staropolski Basi]” — dobre dla UX i dodatkowo sygnalizuje Google relację (to nie jest kradzież, to pochodna z przypisaniem).
4. Twardy próg produktowy: jeśli fork ma < 30% unikalnego tekstu względem oryginału (prosty porównywacz Levenshteina/shingling na `recipeIngredient` + `recipeInstructions` przy zapisie), domyślnie `noindex` i komunikat do autora „Twoja wersja jest bardzo podobna do oryginału — możesz ją zapisać prywatnie albo dodać, co zmieniłaś”.

---

## 2. Structured data (JSON-LD)

Zasady ogólne Google (2026): JSON-LD to jedyny **rekomendowany** format (Google jednoznacznie preferuje go nad Microdata/RDFa), umieszczony w `<script type="application/ld+json">` w `<head>` lub `<body>`. Dane muszą **odzwierciedlać widoczną treść strony** — Google traktuje niezgodność jako naruszenie ogólnych wytycznych structured data (`sd-policies`) niezależnie od typu.

### 2.1 Recipe

Źródło: `developers.google.com/search/docs/appearance/structured-data/recipe` (zweryfikowane wrzesień 2026).

**Wymagane przez Google** (bez nich strona nie kwalifikuje się do rich result / recipe carousel):
- `name`
- `image` (URL lub `ImageObject`; min. 50 000 pikseli w iloczynie wymiarów; zalecane proporcje 16:9, 4:3 i 1:1 — Kuking i tak generuje warianty `960px`/`1600px`, patrz `MEDIA_PIPELINE.md`, więc technicznie to tani warunek do spełnienia).

**Stan w kodzie (#1005):** zdjęcie w Kuking jest opcjonalne i przez chwilę po wgraniu nie jest `ready`. Wtedy strona przepisu **nie emituje `Recipe` wcale** (zostaje sam `BreadcrumbList`) — niepełny obiekt nie kwalifikuje się do wyniku rozszerzonego, a w Search Console daje błąd. Logo w zastępstwie odpada: obraz ma przedstawiać danie. `Recipe` pojawia się sam, gdy zdjęcie jest gotowe, pod tym samym adresem przepisu.

**Zalecane** (podnoszą jakość rich result, nie są twarde do kwalifikacji): `author`, `datePublished`, `description`, `prepTime`, `cookTime`, `totalTime`, `recipeYield`, `recipeCategory`, `recipeCuisine`, `keywords`, `recipeIngredient`, `recipeInstructions`, `nutrition`, `video`, `aggregateRating`.

**Ważna zmiana 2026:** Google usunął wsparcie dla zakresów czasu (np. „20–30 min”) w `prepTime`/`cookTime` — akceptowany jest tylko **jeden konkretny czas w ISO 8601** (`PT30M`). Kreator przepisu w Kuking już zbiera `prep_minutes integer` i `cook_minutes integer` jako pojedyncze liczby (nie zakresy) — to jest zgodne z wymogiem bez zmian w UI.

Pełny, poprawny przykład (zwalidowany `python3 -m json.tool`):

```json
{
  "@context": "https://schema.org",
  "@type": "Recipe",
  "name": "Bigos staropolski",
  "image": [
    "https://kuking.pl/storage/recipes/bigos-staropolski-1600x900.jpg",
    "https://kuking.pl/storage/recipes/bigos-staropolski-1200x1200.jpg",
    "https://kuking.pl/storage/recipes/bigos-staropolski-800x600.jpg"
  ],
  "author": {
    "@type": "Person",
    "name": "Basia Kowalska",
    "url": "https://kuking.pl/@basia_kowalska"
  },
  "datePublished": "2026-01-12T18:04:00+01:00",
  "dateModified": "2026-02-03T09:12:00+01:00",
  "description": "Tradycyjny bigos gotowany dwa dni, z kapustą kiszoną, białą kapustą i trzema rodzajami mięsa. Przepis od babci, sprawdzony przez lata.",
  "prepTime": "PT30M",
  "cookTime": "PT180M",
  "totalTime": "PT210M",
  "recipeYield": "6 porcji",
  "recipeCategory": "Danie główne",
  "recipeCuisine": "polska",
  "keywords": "bigos, kapusta kiszona, kuchnia staropolska, danie jednogarnkowe",
  "recipeIngredient": [
    "1 kg kapusty kiszonej",
    "0,5 kg kapusty białej",
    "300 g wieprzowiny (łopatka)",
    "200 g wołowiny",
    "150 g wędzonego boczku",
    "150 g kiełbasy wiejskiej",
    "2 cebule",
    "5 suszonych grzybów",
    "3 łyżki koncentratu pomidorowego",
    "10 ziaren jałowca",
    "4 liście laurowe",
    "sól i pieprz do smaku"
  ],
  "recipeInstructions": [
    {
      "@type": "HowToStep",
      "name": "Przygotuj kapustę",
      "text": "Kapustę kiszoną odciśnij z nadmiaru soku, kapustę białą poszatkuj i sparz wrzątkiem.",
      "url": "https://kuking.pl/przepisy/bigos-staropolski#krok-1",
      "image": "https://kuking.pl/storage/recipes/bigos-krok-1.jpg"
    },
    {
      "@type": "HowToStep",
      "name": "Podsmaż mięsa",
      "text": "Pokrojone w kostkę mięso i boczek obsmaż na rozgrzanym tłuszczu, dodaj pokrojoną cebulę.",
      "url": "https://kuking.pl/przepisy/bigos-staropolski#krok-2",
      "image": "https://kuking.pl/storage/recipes/bigos-krok-2.jpg"
    },
    {
      "@type": "HowToStep",
      "name": "Połącz składniki i duś",
      "text": "Połącz kapustę z mięsem, dodaj namoczone grzyby, koncentrat, przyprawy. Duś pod przykryciem co najmniej 3 godziny, mieszając co jakiś czas.",
      "url": "https://kuking.pl/przepisy/bigos-staropolski#krok-3",
      "image": "https://kuking.pl/storage/recipes/bigos-krok-3.jpg"
    },
    {
      "@type": "HowToStep",
      "name": "Odstaw i odgrzej",
      "text": "Najlepiej smakuje odgrzewany następnego dnia, gdy smaki się przegryzą."
    }
  ]
}
```

Mapowanie na kolumny (żeby implementacja była jednoznaczna):

| Pole JSON-LD | Źródło w bazie |
|---|---|
| `name` | `recipes.title` |
| `image` | `media` powiązane przez `recipes.hero_media_id` + warianty z `MEDIA_PIPELINE.md` |
| `author.name`, `author.url` | `profiles.display_name`, `profiles.username` autora (`recipes.author_id`) |
| `datePublished` | `recipes.published_at` |
| `dateModified` | `recipes.updated_at` (albo `MAX(recipe_versions.created_at)`) |
| `description` | `recipes.summary` |
| `prepTime`, `cookTime` | `ISO8601(recipes.prep_minutes)`, `ISO8601(recipes.cook_minutes)` — konwersja `PT{n}M` |
| `totalTime` | `ISO8601(prep_minutes + cook_minutes)` |
| `recipeYield` | `recipes.servings` sformatowane jako „{n} porcji” |
| `recipeIngredient` | `recipe_ingredients` posortowane po `position`, sformatowane `quantity unit ingredient_text` |
| `recipeInstructions[].text` | `recipe_steps.instruction` posortowane po `position` |
| `recipeInstructions[].image` | `recipe_steps.media_id` jeśli ustawione |

**`aggregateRating` — uczciwa dyskusja.** Google wymaga, żeby `aggregateRating` **odzwierciedlał prawdziwe, zebrane oceny** i wprost zabrania samodzielnie ustalanych/"self-serving" ocen (np. sztywnego „4.8” wpisanego przez właściciela strony). Ma też wymagane pola `ratingValue`, `ratingCount`/`reviewCount` i typowo skalę 1–5 (`bestRating`/`worstRating`).

Kuking **nie ma** skali gwiazdkowej 1–5 — ma `cooked_events.would_make_again boolean`. To jest sygnał binarny („zrobię ponownie: tak/nie”), nie ocena punktowa. Rekomendacja: **nie emitować `aggregateRating` w MVP.** Powody:
1. Sztuczne przeliczenie `would_make_again` (bool) na `ratingValue` w skali 1–5 byłoby interpretacją, nie realnym pomiarem — dokładnie to, przed czym ostrzega Google jako „ratings not based on genuine, first-hand reviews”.
2. Ryzyko manualnej akcji/utraty zaufania do wszystkich structured data domeny jest nieproporcjonalne do korzyści (gwiazdki w SERP nie są celem — celem jest powrót bez Google, zgodnie z `PRODUCT.md`).

Zamiast tego: pokaż na stronie widocznie (nie w JSON-LD) „X osób ugotowało · Y% zrobiłoby ponownie” jako element UI, a w structured data użyj `interactionStatistic` (patrz niżej) dla `COUNT(cooked_events)` i `COUNT(comments)` — to są policzalne, prawdziwe interakcje, nie subiektywna ocena.

Jeśli w V1/V2 powstanie prawdziwa skala ocen (np. `rating 1–5` dodana świadomie do `cooked_events` albo osobnej tabeli `recipe_ratings`), dopiero wtedy emitować `aggregateRating` z `ratingCount = COUNT(DISTINCT user_id)`.

`interactionStatistic` na `Recipe` (opcjonalne — Google nie wykorzystuje go dziś do kwalifikacji do Recipe rich results, ale jest poprawnym, prawdziwym rozszerzeniem schema.org i część docelowego kontekstu dla AI Overviews/agentic search):

```json
"interactionStatistic": [
  {
    "@type": "InteractionCounter",
    "interactionType": "https://schema.org/CommentAction",
    "userInteractionCount": 14
  }
]
```

(`CommentAction` count = `SELECT COUNT(*) FROM comments WHERE recipe_id = ...` — liczyć tylko `status = 'published'`, żeby nie ujawniać usuniętych/ukrytych komentarzy w publicznych danych.)

### 2.2 ProfilePage + Person

Źródło: `developers.google.com/search/docs/appearance/structured-data/profile-page` (wrzesień 2026).

**Wymagane:** `mainEntity` (typu `Person` lub `Organization`) oraz `name` wewnątrz `mainEntity`.

**Zalecane:** `dateCreated`, `dateModified`, `image`, `description`, `alternateName`, `identifier`, `sameAs`, `interactionStatistic` (statystyki *o* profilu — np. liczba followersów), `agentInteractionStatistic` (statystyki *działań* profilu — np. liczba opublikowanych treści).

Wymóg kwalifikacji: strona musi mieć jako **główny temat** jedną osobę/organizację powiązaną z serwisem — profil użytkownika Kuking pasuje wprost do przykładu z dokumentacji Google.

```json
{
  "@context": "https://schema.org",
  "@type": "ProfilePage",
  "dateCreated": "2025-11-02T09:00:00+01:00",
  "dateModified": "2026-08-30T21:15:00+01:00",
  "mainEntity": {
    "@type": "Person",
    "name": "Basia Kowalska",
    "alternateName": "basia_kowalska",
    "identifier": "9f2c1e2a-1b3d-4e5f-8a6b-7c8d9e0f1a2b",
    "description": "Gotuję codziennie dla rodziny. Uwielbiam kuchnię staropolską i przetwory.",
    "image": "https://kuking.pl/storage/avatars/basia_kowalska.jpg",
    "url": "https://kuking.pl/@basia_kowalska",
    "interactionStatistic": [
      {
        "@type": "InteractionCounter",
        "interactionType": "https://schema.org/FollowAction",
        "userInteractionCount": 128
      }
    ],
    "agentInteractionStatistic": [
      {
        "@type": "InteractionCounter",
        "interactionType": "https://schema.org/WriteAction",
        "userInteractionCount": 47
      }
    ]
  }
}
```

Mapowanie: `dateCreated` = `profiles.created_at`, `dateModified` = `profiles.updated_at`, `name` = `profiles.display_name`, `alternateName`/`identifier` w URL = `profiles.username`, `FollowAction count` = `SELECT COUNT(*) FROM follows WHERE followed_id = :user_id`, `WriteAction count` = opublikowane posty + przepisy autora.

**Bezpieczeństwo danych:** nigdy nie umieszczaj w `Person` prawdziwego adresu e-mail, nawet zahaszowanego — `identifier` to wewnętrzne UUID, nie PII. Profil bez żadnej publicznej treści (zero opublikowanych postów/przepisów, świeżo założone konto) **nie powinien** w ogóle emitować `ProfilePage` — patrz sekcja 3.

### 2.3 BreadcrumbList

Zalecany na **każdej** publicznej stronie treści (przepis, post, profil) — Google jawnie rekomenduje to jako element podstawowy niezależnie od typu strony.

```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "name": "Kuking",
      "item": "https://kuking.pl/"
    },
    {
      "@type": "ListItem",
      "position": 2,
      "name": "Odkrywaj",
      "item": "https://kuking.pl/odkryj"
    },
    {
      "@type": "ListItem",
      "position": 3,
      "name": "Bigos staropolski"
    }
  ]
}
```

Uwaga: ostatni element (bieżąca strona) może pominąć `item` — to zgodne z oficjalnym przykładem Google. Dla profilu: `Kuking → @basia_kowalska`. Dla posta: `Kuking → @basia_kowalska → [pierwsze słowa wpisu]`.

### 2.4 WebSite / Organization

```json
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "name": "Kuking",
  "url": "https://kuking.pl/",
  "inLanguage": "pl-PL",
  "publisher": {
    "@type": "Organization",
    "name": "Kuking",
    "url": "https://kuking.pl/",
    "logo": {
      "@type": "ImageObject",
      "url": "https://kuking.pl/images/logo-512.png",
      "width": 512,
      "height": 512
    },
    "sameAs": [
      "https://www.facebook.com/kuking.pl",
      "https://www.instagram.com/kuking.pl"
    ]
  }
}
```

Wstaw raz, globalnie, np. w layoucie strony głównej. **`SearchAction`/„sitelinks search box” pomiń świadomie** — Google wycofał tę funkcję z wyników wyszukiwania w listopadzie 2024 r.; stare znaczniki nie szkodzą, ale dodawanie nowych nie daje dziś żadnego efektu wizualnego w SERP (może mieć znaczenie w przyszłości dla agentic search, ale to nie jest dziś priorytet MVP) `[do weryfikacji: status SearchAction w kontekście AI Mode/agentic search może się zmienić]`.

### 2.5 Walidacja w CI

Każdy JSON-LD blok renderowany przez Blade powinien przechodzić dwa testy zanim trafi na produkcję:
1. `json_decode($blade->render(), flags: JSON_THROW_ON_ERROR)` — składniowa poprawność (odpowiednik `python3 -m json.tool` użytego przy tworzeniu tego dokumentu).
2. Snapshot test przeciw Google Rich Results Test (ręcznie przy większych zmianach schematu; nie da się w pełni zautomatyzować bez zewnętrznego API).

---

## 3. Co indeksować, a czego nie

| Typ treści | Indeksacja | Uzasadnienie |
|---|---|---|
| Publiczny przepis (`recipes.visibility='public'`, `status='published'`) | `index, follow` | Realna treść, cel akwizycji |
| Publiczny wpis z tekstem i/lub zdjęciem (`posts.visibility='public'`, `status='published'`) | `index, follow` | j.w., o ile ma choć minimalną treść (patrz niżej) |
| Wpis samo-zdjęcie bez tekstu | `index, follow`, ale **bez** promowania w sitemapie priorytetowej | Nie jest spamem, ale ma niską wartość tekstową dla Google — niech żyje dla ludzi (link, udostępnienie), nie forsować w crawl budgecie |
| Profil z ≥1 publiczną treścią | `index, follow` + `ProfilePage` | Realna, zweryfikowana obecność |
| Profil bez żadnej publicznej treści (świeże konto, samo „popatrzę”) | `noindex, follow` | Zero wartości dla wyszukującego, ryzyko cienkiej treści na skalę (tysiące pustych profili) |
| Strona tagu z ≥1 wpisem widocznym dla wszystkich (`/tag/{slug}`) | `index, follow` | Realna treść; warunek = ten sam zakres co licznik w spisie tagów (D-087) |
| Strona tagu bez publicznego wpisu (większość słownika z `TagSeeder`) | `noindex, follow`, link w spisie `/tagi` z `rel="nofollow"`, poza sitemapą | Strona zostaje dla ludzi (prawdziwe zero, „Dodaj wpis”), ale ~1400 prawie identycznych pustych stron to cienka treść na skalę (issue #1007). Wraca do indeksu sama po pierwszym publicznym wpisie |
| Treść `visibility IN ('followers','private')` | `noindex, nofollow` + brak w sitemapie + wymagany auth do renderu | Nigdy nie może wyciec do crawlera |
| Konto `status IN ('suspended','banned','pending_delete')` | `noindex`, treść zwraca 410/404 zgodnie z polityką retencji | Nie utrzymywać w indeksie kont usuniętych/zbanowanych |
| Treść zgłoszona i ukryta (`status='hidden'`/`'removed'` po `moderation_actions`) | `noindex, nofollow`, HTTP 410 (removed) lub 200+noindex (hidden, w toku triage) | Zgodność z DSA (decyzja + możliwość odwołania), zero ryzyka rankingowego z treści naruszającej zasady |
| `/szukaj`, `/powiadomienia`, `/ustawienia/*`, `/admin/*` | `noindex, nofollow` (+ `Disallow` w `robots.txt` dla `/ustawienia`, `/admin`, `/powiadomienia` — auth-only, crawler i tak ich nie zobaczy, ale to tania dodatkowa warstwa) | Brak wartości publicznej, ryzyko crawl budgetu |
| `/home`, `/dodaj`, `/zeszyt` (widoki wymagające loginu) | poza indeksem z definicji (auth wall) | j.w. |
| Kolekcje prywatne | `noindex, nofollow` | `collections.visibility='private'` domyślne |
| Kolekcje publiczne | `index, follow` | Realna, kuracyjna treść — dobry sygnał jakości |

### 3.1 `robots.txt`

```text
User-agent: *
Disallow: /ustawienia/
Disallow: /admin/
Disallow: /powiadomienia
Disallow: /home
Disallow: /dodaj
Allow: /

Sitemap: https://kuking.pl/sitemap_index.xml
```

Realny plik generuje `app/Http/Controllers/SitemapController.php::robots()` — adresy tam i tu muszą się zgadzać; do 12 września 2026 ten dokument (i sam kontroler) miały `/search`, `/home` i `/add` po angielsku, czyli pod adresami, których serwis nie ma, więc wyszukiwarka i ekran dodawania nie były w praktyce wyłączone z indeksowania.

Nie blokuj `/szukaj` w `robots.txt` samodzielnie jako jedyny mechanizm — patrz 1.3 i 3.2 (meta robots jest tu ważniejszy, bo strony `/szukaj?q=` mogą być odkryte przez linki zewnętrzne mimo `Disallow`, a `Disallow` uniemożliwia Google **zobaczenie** meta tagu `noindex` na tej stronie, więc de facto może **utrzymać** ją w indeksie bez treści, jeśli już raz została odkryta gdzieś indziej). Dlatego: `/szukaj` ma `noindex` w HTML, ale **nie** ma wpisu w `robots.txt` — niech Google może ją odwiedzić, zobaczyć `noindex` i faktycznie ją wyrzucić/nie dodać.

### 3.2 Meta robots + `X-Robots-Tag`

Dla stron renderowanych przez Blade: tag w `<head>`:

```html
<meta name="robots" content="noindex, follow">
```

Dla odpowiedzi, które nie są pełnym HTML-em (np. JSON API, obrazy z prywatnych zdjęć, eksport ZIP), użyj nagłówka HTTP zamiast meta tagu:

```php
// middleware NoIndexHeader dla tras: api/*, storage/private/*, export downloads
return $next($request)->header('X-Robots-Tag', 'noindex, noarchive');
```

Reguła implementacyjna: centralny middleware `EnsureVisibilityHeaders`, dopinany per trasa w `routes/web.php`, żeby nikt nie musiał pamiętać o tym ręcznie w każdym kontrolerze — brak `noindex` na stronie prywatnej to poważniejszy błąd niż jego nadmiarowe użycie.

---

## 4. Sitemapy

### Aktualna bramka kompletności (#1055)

Obecna mapa `/sitemap.xml` czyta wpisy i przepisy partiami po 500,
kursorem `chunkById`. Nie dodajemy do tych zapytań sortowania datą:
porządek musi odpowiadać kluczowi kursora, inaczej kolejne partie
pomijają adresy lub je powtarzają. Kolejność adresów w XML nie jest
rankingiem treści. Test `SitemapChunkCompletenessTest` porównuje pełny
zbiór i liczności adresów dla 1001 rekordów każdego typu, przy datach
rosnących, malejących i równych; sprawdza także odczyt cache i wykluczenia.
Poniższy podział na pliki pozostaje planem większej skali. Poprawka #1055
nie zmienia czasu cache, reguł profili ani wyboru adresów pytań.

### Świeżość zapamiętanej mapy (#1006)

Lista adresów stoi w cache pod kluczem `sitemap.urls`
(`App\Support\MapaStrony::KLUCZ`) z czasem życia sześciu godzin. Ten czas
jest **tylko zabezpieczeniem awaryjnym** — docelowa zwłoka po zmianie
widoczności to **zero**: następne żądanie `/sitemap.xml` po zatwierdzeniu
zapisu składa mapę od nowa. Sześć godzin to górna granica wyłącznie dla
zmian, które ominą Eloquenta (surowe `UPDATE` w bazie).

Klucz kasują haki modeli rejestrowane w `AppServiceProvider`
(`MapaStrony::zarejestrujHaki()`):

| Model | Zdarzenie |
|---|---|
| `Recipe`, `Post` | utworzenie; zmiana `status`, `visibility`, `published_at`, `author_id`, `deleted_at` (w tym przywrócenie); usunięcie |
| `Recipe` | dodatkowo zmiana `slug` (inny adres) |
| `Post` | dodatkowo zmiana `body` (wpis bez treści nie wchodzi) i `kind` |
| `Profile` | zmiana `username` (inny adres), usunięcie |
| `User` | zmiana `status` (ban, zawieszenie, usuwanie konta, zatarcie) |

Kasowanie idzie przez `DB::afterCommit()`: w transakcji dopiero po COMMIT,
po ROLLBACK wcale. Kasowany jest **wyłącznie** ten klucz, nigdy cały
magazyn cache. Zapis bez wpływu na mapę (np. tytuł przepisu) klucza nie
rusza. Pilnuje tego `MapaStronyNadazaZaWidocznosciaTest`. Nowy typ treści
w mapie = nowy wiersz w `MapaStrony::KOLUMNY`.

Limity Google (2026, niezmienione od lat): **max 50 000 URL-i i 50 MB (nieskompresowane) na plik sitemap**; przekroczenie limitu URL-i → Google ignoruje nadmiar; przekroczenie 50 MB → ryzyko odrzucenia całego pliku. Rozwiązanie standardowe: **sitemap index**.

### 4.1 Struktura

```text
/sitemap_index.xml
  → /sitemap-recipes-{n}.xml     (do 50 000 URL / plik)
  → /sitemap-posts-{n}.xml
  → /sitemap-profiles-{n}.xml
  → /sitemap-collections-{n}.xml
```

Segmentacja **po typie treści** (nie losowa) — ułatwia diagnozę w Search Console (osobny wgląd w indeksację przepisów vs profili) i pozwala inaczej ustawiać priorytety/`lastmod` per typ.

### 4.2 Generowanie w tle — `GenerateSitemapChunk`

Zgodnie z `ARCHITECTURE.md` (`QUEUE_CONNECTION=database`, joby: `GenerateSitemapChunk`). Zasady, żeby **nie wysadzić bazy**:

1. **Nie** `SELECT * FROM recipes` w pamięci naraz. Chunkować kursorem po `id`:

```php
Recipe::query()
    ->where('status', 'published')
    ->where('visibility', 'public')
    ->whereNull('deleted_at')
    ->orderBy('id')
    ->chunkById(1000, function ($recipes) use (&$writer) {
        foreach ($recipes as $recipe) {
            $writer->addUrl($recipe->slug, $recipe->updated_at);
        }
    });
```

2. Job per chunk (nie jeden monolityczny job na cały sitemap): `GenerateSitemapChunk::dispatch($type, $chunkIndex)`. Każdy chunk = maks. 50 000 wierszy, zapisany jako osobny plik XML w object storage (nie w bazie, nie w pamięci procesu web).
3. Odśwież **w nocy** (niski ruch), harmonogram co 24h wystarcza dla MVP — Kuking nie jest serwisem newsowym, świeżość co kilka godzin nie jest potrzebna.
4. `lastmod` = `GREATEST(recipes.updated_at, MAX(recipe_versions.created_at))` — realna data ostatniej **merytorycznej** zmiany, nie data regeneracji sitemapy. Fałszywie świeży `lastmod` (ustawiany przy każdym uruchomieniu joba, niezależnie od realnej zmiany treści) jest traktowany przez Google jako sygnał niewiarygodny i z czasem ignorowany.
5. Indeks (`sitemap_index.xml`) generuj jako lekki, szybki job osobno, uruchamiany **po** zakończeniu wszystkich chunków (job chain / batch w Laravel Queue), żeby nigdy nie wskazywał na plik, który jeszcze nie istnieje.
6. Kompresja `.xml.gz` — Google akceptuje bez dodatkowej konfiguracji, warto włączyć od razu przy skali > kilku tysięcy URL-i (redukcja transferu 70–90%).

### 4.3 Co wchodzi do sitemapy

**Adres wpisu przez `Post::url()` (#968).** Pytanie ma jeden adres, `/pytania/{id}`; `/wpisy/{id}` pytania przekierowuje na niego 301 (po sprawdzeniu dostępu). Mapa ogłasza więc pytania wyłącznie pod `/pytania/{id}` i obejmuje także pytanie z samym tytułem (`body` puste — tytuł jest obowiązkowy). Zwykłe wpisy bez `body` nadal nie wchodzą: to zapowiedzi przepisów.

Tylko URL-e z sekcji 3 oznaczone `index` — status HTTP 200, brak `noindex`, `visibility='public'`. Filtr identyczny z tym używanym do generowania meta robots, żeby nie rozjechały się dwa niezależne źródła prawdy (jedna metoda `RecipePolicy::isPubliclyIndexable()` używana w obu miejscach).

---

## 5. Wydajność / Core Web Vitals

Stan progów 2026 (web.dev, potwierdzone): **LCP** dobry < 2.5 s, **CLS** dobry < 0.1, **INP** dobry < 200 ms (mierzone jako 75. percentyl realnych wizyt — RUM, nie lab data). INP zastąpił FID w marcu 2024 i pozostaje trzecim filarem CWV w 2026; jest to obecnie **najczęściej niespełniany** wskaźnik w branży (raporty wskazują nawet ~40%+ witryn poniżej progu), więc warto go traktować priorytetowo, nie jako dodatek.

Dla Kuking — serwisu, gdzie **zdjęcie jest głównym elementem strony** (hero image przepisu, wpisu, avatar) — konkretne działania:

### 5.1 Obrazy — LCP

- **`fetchpriority="high"`** na hero image przepisu/posta (element LCP) — jawna podpowiedź dla przeglądarki, żeby pobrać go przed innymi zasobami.
- **`loading="lazy"`** na wszystkim **poza** hero image (miniatury w feedzie, avatar w komentarzach, zdjęcia „Ugotowałem” poniżej fold).
- `srcset` + `sizes` z wariantami z `MEDIA_PIPELINE.md` (`thumb 320px`, `feed 960px`, `large 1600px`):

```html
<img
  src="https://kuking.pl/storage/recipes/bigos-960.webp"
  srcset="
    https://kuking.pl/storage/recipes/bigos-320.webp 320w,
    https://kuking.pl/storage/recipes/bigos-960.webp 960w,
    https://kuking.pl/storage/recipes/bigos-1600.webp 1600w"
  sizes="(max-width: 640px) 100vw, (max-width: 1024px) 80vw, 960px"
  width="1600" height="900"
  alt="Bigos staropolski w garnku, widok z góry"
  fetchpriority="high"
  decoding="async">
```

- Zawsze `width`/`height` (lub `aspect-ratio` w CSS) na `<img>` — zapobiega CLS przy doładowaniu obrazu.
- **AVIF jako pierwszy wybór, WebP jako fallback**, oryginał (JPEG) tylko jako ostateczny fallback dla bardzo starych przeglądarek — biorąc pod uwagę grupę 50+ i realne udziały starszych Androidów, `<picture>` z trzema źródłami jest tańsze niż ryzyko wykluczenia:

```html
<picture>
  <source type="image/avif" srcset="bigos-960.avif">
  <source type="image/webp" srcset="bigos-960.webp">
  <img src="bigos-960.jpg" width="1600" height="900" alt="…" fetchpriority="high">
</picture>
```

- Preload hero image na stronie przepisu/posta w `<head>`, żeby ominąć opóźnienie odkrycia przez przeglądarkę:

```html
<link rel="preload" as="image" href="https://kuking.pl/storage/recipes/bigos-960.webp" fetchpriority="high">
```

### 5.2 INP przy Livewire

Livewire (v4, full-stack reaktywność server-side) jest z natury bardziej podatny na INP niż statyczny HTML, bo każda interakcja może czekać na round-trip serwera. Zasady:

- **Debounce** na polach tekstowych z `wire:model.live.debounce.400ms`, nigdy gołe `wire:model.live` na polu tekstowym (np. wyszukiwarka, autosave kreatora przepisu) — każde naciśnięcie klawisza bez debounce to potencjalny request i blokada głównego wątku.
- Akcje jednorazowe (kliknięcie „Ugotowałem”, „Zapisz do kolekcji”, „Follow”) — użyj `wire:click` z natychmiastowym **optymistycznym stanem UI** (Alpine.js lokalnie zmienia wygląd przycisku na "zapisano" zanim odpowiedź serwera wróci), żeby interakcja nie czekała na cały cykl Livewire dla samego feedbacku wizualnego. Serwer w tle potwierdza/koryguje.
- `wire:loading` z konkretnym, małym wskaźnikiem przy przycisku (nie globalny spinner blokujący całą stronę) — ważne dla percepcji INP przez użytkowników 50+, którzy inaczej klikają drugi raz („nie zadziałało”), co tylko pogarsza sytuację (podwójny submit).
- Autosave kreatora przepisu (krok 1/2/3): zapis w tle (`wire:model.live.debounce.1000ms` + async job), **nigdy** blokująco na klawiszu — użytkownik pisze składniki, nie czeka na serwer po każdej literze.
- Duże listy (feed, wyniki search) renderowane przez Livewire: paginacja/`wire:key` poprawnie ustawione na każdym elemencie listy, żeby Livewire diffował DOM efektywnie zamiast przerenderowywać całość przy każdej aktualizacji — to bezpośrednio wpływa na INP przy scrollowaniu + doładowaniu.

### 5.3 Cache i nagłówki

- Statyczne warianty obrazów: `Cache-Control: public, max-age=31536000, immutable` (nazwy plików z hashem/UUID — nigdy nie nadpisuj istniejącego pliku pod tym samym URL-em).
- HTML publicznych stron (przepis, profil, post): krótki edge cache (np. `s-maxage=300, stale-while-revalidate=600`) jeśli używany jest CDN — pozwala odciążyć serwer bez ryzyka pokazywania bardzo nieaktualnej treści po edycji.
- Response dla treści prywatnych/auth: zawsze `Cache-Control: private, no-store` — nigdy cache współdzielony dla stron `/home`, `/ustawienia/*`.

### 5.4 Fonty

- Maks. 2 rodziny fontów, subsetting do zestawu znaków PL (żeby nie ciągnąć całego Unicode).
- `font-display: swap` zawsze — tekst musi być czytelny natychmiast (kluczowe dla grupy 50+ i dla LCP, jeśli LCP jest tekstem, a nie obrazem — na stronach bez hero image, np. profil bez avatara).
- Samohostowane pliki fontów (własna domena, nie zewnętrzny CDN), żeby uniknąć dodatkowego DNS lookup/connection dla renderu tekstu.

---

## 6. Międzynarodowo: tylko `pl-PL`

**`hreflang` nie jest potrzebny — potwierdzone.** Oficjalne stanowisko Google (John Mueller, Search Central): jeśli strona istnieje w jednym języku/regionie, `hreflang` „nie ma sensu” i dodawanie go jest zbędne — służy wyłącznie do wskazywania **wariantów** tej samej treści w innych językach/regionach, a takich wariantów Kuking nie ma i się nie planuje.

Co warto mieć zamiast tego (tanie, jednorazowe):
- `<html lang="pl">` na każdej stronie.
- `<meta property="og:locale" content="pl_PL">` (dla udostępnień na Facebooku — kluczowy kanał dla grupy 50+, patrz `GROWTH.md`).
- `inLanguage: "pl-PL"` w `WebSite`/`Recipe` JSON-LD (już w przykładach powyżej) — to **nie** jest to samo co hreflang, to metadana o samej treści, nadal warta dodania.

Jeśli w przyszłości powstałaby wersja np. dla polskiej diaspory z inną domeną/subdomeną — dopiero wtedy `hreflang` staje się relewantny.

---

## 7. Ryzyko: cienkie treści, spam, kradzione przepisy, „scaled content abuse”

Zweryfikowane 2026: polityka Google **„scaled content abuse”** (następczyni „spammy auto-generated content”, rozszerzona marcową aktualizacją spamu 2024, aktywnie i **agresywnie egzekwowana w marcu 2026** — raportowane spadki ruchu 50–80% dla serwisów publikujących setki/tysiące stron bez nadzoru redakcyjnego) **nie zakazuje treści AI jako takiej**. Zakazuje **wolumenu treści bez rzeczywistej wartości dla użytkownika**, niezależnie czy powstała z AI, przez ludzi, czy przez skrobanie/łączenie cudzych treści. To jest dokładnie zgodne z zasadą produktową Kuking („NIE generujemy masowo treści AI pod SEO”) — ale trzeba się chronić także przed **niezamierzonym** wpadnięciem w ten wzorzec przez samą skalę UGC.

Konkretne wektory ryzyka dla Kuking i mitygacje:

| Ryzyko | Mitygacja |
|---|---|
| Setki niemal identycznych „Moja wersja” tego samego przepisu (patrz 1.4) | Próg unikalności treści + `noindex` domyślny dla niskiej wariacji (sekcja 1.4) |
| Wpisy bez żadnej treści tekstowej, samo zdjęcie, masowo publikowane przez boty/spamerów | Rate limit na publikację (już w `FEATURES.md` → Trust: rate limits), moderacja + `reports` (schemat już ma `target_type IN (...,'post',...)`) |
| Kradzione/skopiowane przepisy z zewnętrznych blogów wklejone 1:1 | `recipes.source_type` wymusza deklarację źródła w kreatorze (własny/rodzinny/adaptacja/zewnętrzny); `source_url` obowiązkowy przy `'external'`; polityka moderacji z `MODERATION.md` („Nie publikować pełnych cudzych treści bez praw”) + prosty fingerprint tekstu (shingling na `recipe_steps.instruction`) porównywany z bazą znanych blogów przy `'external'` bez `source_url` — flaga do ręcznej moderacji, nie automatyczny ban |
| Konta zakładane masowo do zalewania platformy niskiej jakości treścią pod SEO (farming) | Rate limit rejestracji per IP/urządzenie, `email_verified_at` wymagany przed publikacją, brak indeksacji świeżych pustych profili (sekcja 3) |
| Ręczna pokusa: „dodajmy 500 przepisów z importu, żeby było co pokazać na start” | Twardy zakaz produktowy z `FEATURES.md` („Nie wcześnie: masowy import cudzych treści”) — nie łamać go nawet punktowo dla „SEO na start”; cold start ma się opierać na 20–150 realnych kucharzy (`SEO_ANALYTICS_GROWTH.md`) |
| Duplicate content między wariantami URL (z i bez `www`, http/https, trailing slash) | Wymuszony jeden kanoniczny host (`https://kuking.pl`, bez `www`) przez przekierowania 301 na poziomie edge/serwera, `<link rel="canonical">` na każdej stronie treści wskazujący na siebie |

**Zasada procesowa, nie tylko techniczna:** każda funkcja, która generuje nowe publiczne URL-e w skali (fork przepisu, przyszłe „kolekcje tematyczne” generowane automatycznie, przyszłe strony tagów/kategorii) przechodzi przez pytanie kontrolne przed wdrożeniem: *„Czy każda z tych stron ma samodzielną wartość dla kogoś, kto na nią trafi z Google, czy tylko mnoży wolumen?”* — jeśli odpowiedź nie jest jednoznacznie pozytywna, strona dostaje `noindex` do czasu udowodnienia wartości (np. ruchem organicznym, czasem na stronie).

---

## 8. Checklista wdrożeniowa (do zamiany na issues)

**Fundamenty (przed pierwszym publicznym launchem):**
- [ ] `RecipeSlugger` — transliteracja PL + generowanie unikalnego slugu z sufiksem przy kolizji
- [ ] Tabela `recipe_slug_redirects` + middleware 301 fallback przy zmianie tytułu
- [ ] `<link rel="canonical">` na każdej stronie treści (przepis, post, profil, kolekcja)
- [ ] Wymuszony kanoniczny host (bez `www`, https-only) na poziomie serwera/edge
- [ ] Meta robots `noindex, follow` na `/szukaj`, pustych profilach, treściach `followers`/`private`
- [ ] `X-Robots-Tag: noindex` middleware dla `api/*`, eksportów, storage prywatnego
- [ ] `robots.txt` z `Disallow` dla `/ustawienia`, `/admin`, `/powiadomienia`, `/home`, `/dodaj` + link do `sitemap_index.xml`
- [ ] `<html lang="pl">` + `og:locale=pl_PL` globalnie

**Structured data:**
- [ ] JSON-LD `Recipe` na `/przepisy/{slug}` z mapowaniem z sekcji 2.1 (bez `aggregateRating`)
- [ ] JSON-LD `ProfilePage`+`Person` na `/@username` (tylko gdy profil ma ≥1 publiczną treść)
- [ ] JSON-LD `BreadcrumbList` na wszystkich stronach treści
- [ ] JSON-LD `WebSite`+`Organization` globalnie w layoucie
- [ ] Test JSON-LD w CI: `json_decode(..., JSON_THROW_ON_ERROR)` na każdym renderze
- [ ] Ręczna walidacja w Google Rich Results Test przed każdym launchem większej zmiany schematu

**Sitemapy:**
- [ ] Job `GenerateSitemapChunk` z `chunkById(1000, ...)`, chunk = osobny plik, max 50k URL/plik
- [ ] `sitemap_index.xml` generowany **po** zakończeniu wszystkich chunków (batch/chain jobów)
- [ ] `lastmod` = realna data zmiany merytorycznej (`updated_at`/`recipe_versions`), nie data regeneracji
- [ ] Filtr indeksowalności współdzielony między sitemapą a meta robots (jedna metoda `isPubliclyIndexable()`)
- [ ] Harmonogram nocny (co 24h), kompresja `.xml.gz` od progu kilku tysięcy URL-i

**Wydajność:**
- [ ] `fetchpriority="high"` + `<link rel="preload">` na hero image przepisu/posta
- [ ] `srcset`/`sizes` + `<picture>` AVIF/WebP/JPEG fallback na wszystkich zdjęciach treści
- [ ] `width`/`height` lub `aspect-ratio` na każdym `<img>` (zero CLS z obrazów)
- [ ] `wire:model.live.debounce` (nie gołe `.live`) na wszystkich polach tekstowych Livewire
- [ ] Optymistyczny UI (Alpine) na akcjach jednorazowych: Ugotowałem, Follow, Zapisz do kolekcji
- [ ] Cache nagłówki: `immutable` dla assetów, `private, no-store` dla stron auth
- [ ] Pomiar RUM (Core Web Vitals w PostHog lub Search Console) od dnia pierwszego publicznego ruchu

**Anty-spam / anty-duplikacja (przed V1 z „Moja wersja”):**
- [ ] Próg unikalności treści dla forków przepisu + domyślny `noindex` poniżej progu
- [ ] Rate limit publikacji per konto/IP (posty, przepisy, komentarze)
- [ ] Obowiązkowy `source_url` przy `source_type='external'` w kreatorze przepisu
- [ ] Zasada „pytanie kontrolne przed każdą nową klasą generowanych URL-i” udokumentowana w `CONTRIBUTING`/`AGENTS.md`

---

## Źródła

- [Recipe Schema Markup — Google Search Central](https://developers.google.com/search/docs/appearance/structured-data/recipe)
- [Profile Page (ProfilePage) Schema Markup — Google Search Central](https://developers.google.com/search/docs/appearance/structured-data/profile-page)
- [How To Add Breadcrumb (BreadcrumbList) Markup — Google Search Central](https://developers.google.com/search/docs/appearance/structured-data/breadcrumb)
- [General Structured Data Guidelines — Google Search Central](https://developers.google.com/search/docs/appearance/structured-data/sd-policies)
- [Google Search's guidance on using generative AI content](https://developers.google.com/search/docs/fundamentals/using-gen-ai-content)
- [Interaction to Next Paint becomes a Core Web Vital — web.dev](https://web.dev/blog/inp-cwv-march-12)
- [Google's August 2026 Spam Update — scaled content abuse case studies](https://www.gsqi.com/marketing-blog/august-2026-google-spam-update-case-studies/)
- [Scaled Content Abuse: Google's March 2026 crackdown](https://www.digitalapplied.com/blog/scaled-content-abuse-google-march-update-ai-pages-decimated)
- [Google Deprecates Sitelinks Search Box](https://www.schemaapp.com/schema-markup/google-deprecates-sitelinks-search-box-what-it-means-for-your-website/)
- [Site Has One Language/Region? No Need To Use hreflang For Google — Search Engine Roundtable](https://www.seroundtable.com/one-language-hreglang-google-23970.html)
- [Managing Multi-Regional and Multilingual Sites — Google Search Central](https://developers.google.com/search/docs/specialty/international/managing-multi-regional-sites)
- Sitemap 50 000 URL / 50 MB limit — [Sitemap Limits: 50000 URLs and 50MB Max](https://library.linkbot.com/what-are-the-url-and-file-size-limits-for-sitemaps-and-how-can-large-sites-adapt/) `[do weryfikacji: dokładny numer aktualnej wersji dokumentacji Google sitemaps.org, limit sam w sobie jest stabilny od lat]`
- Pliki wewnętrzne projektu: `docs/SEO_ANALYTICS_GROWTH.md`, `docs/PRODUCT.md`, `docs/FEATURES.md`, `docs/ARCHITECTURE.md`, `docs/MEDIA_PIPELINE.md`, `docs/MODERATION.md`, `database/reference/schema_mvp.sql`
