## D-158 · Odstęp pod zdjęciem karty wpisu należy do bloku POD zdjęciem i wisi na sąsiedztwie, nie na klasie

**Data:** 11 września 2026 · Zgłosił właściciel · PR #405 · Status: **obowiązuje** ·
rozwinięcie D-154

### Zgłoszenie

> „«z przepisu» i «bigos z cukinii» jest zbyt blisko zdjęcia"

### Co było zmierzone

Chromium, `/home` po zalogowaniu, przerwa liczona **między treścią** bloków:

| para bloków | 1512 px | 390 px |
|---|---:|---:|
| `post-card-head` → zdjęcie | 16 → 16 | 16 → 16 |
| **zdjęcie → `post-card-recipe`** | **0 → 16** | **0 → 16** |
| **karuzela → `post-card-zapisy`** | **0 → 16** | **0 → 16** |
| `post-card-recipe` → `post-card-tagi` | 40 → 40 | 40 → 40 |
| zdjęcie → `post-card-actions` | 17 → 17 | 17 → 17 |

`diff` pomiarów przed i po: zmieniły się **dokładnie dwie pary**, w obu
szerokościach. Zgłoszenie dotyczyło jednej z nich; druga miała tę samą wadę.

### Przyczyna

Cała karta trzyma rytm **dolnym wcięciem bloku wyżej** (`padding-bottom`),
a blok zdjęć takiego wcięcia **nie ma i mieć nie może**: zdjęcie idzie od
krawędzi do krawędzi, karta ma `overflow: hidden`. Para „zdjęcie → blok
tekstu" była więc jedyną, której odstępu nie deklarowała żadna strona.

Odstęp deklaruje strona **dolna**, jako `margin-top` — zgodnie z D-154.

### Reguła wisi na SĄSIEDZTWIE, nie na klasie

```css
.post-card > :is(.photo-grid, .karuzela, .kolaz) + :is(.post-card-recipe, .post-card-zapisy) {
  margin-top: var(--spacing-4);
}
```

Pasek „Z przepisu" **nie zawsze stoi pod zdjęciem**: przy przepisie bez
zdjęcia głównego stoi pod nagłówkiem, we wpisie „ugotowane z przepisu" pod
treścią — i tam przerwa **jest**, zmierzone 16 px. Bezwarunkowy `margin-top`
na klasie zrobiłby w tych stanach 32 px, czyli **poprawiłby jeden stan ekranu
i zepsuł dwa** (D-099, D-106).

### Konsekwencja dla testów, i to jest właściwa treść tego wpisu

> **Odstęp oparty na `+` zależy od kolejności rodzeństwa w DOM-ie, więc test
> musi sprawdzać SĄSIEDZTWO w wyrenderowanym dokumencie, nie tylko obecność
> reguły w arkuszu.**

Sabotaż „wstaw obcy element między zdjęcie a pasek" **wyłącza odstęp, nie
ruszając ani jednej linii CSS-a**. Test, który tego nie łapie, pilnuje połowy
reguły. Strażnik używa więc XPath `preceding-sibling::*[1]`.

### Pomiar liczy przerwę między treścią, nie między krawędziami pudełek

Odstępy tej karty siedzą w `padding`, a padding jest **wewnątrz** pudełka —
różnica krawędzi pokazuje 0 px także tam, gdzie człowiek widzi 16 px.
**Pierwsza wersja pomiaru meldowała zero dla ośmiu par i była fałszywa**;
poprawiona, zanim cokolwiek zmieniono w arkuszu.

Karta z paskiem „Z przepisu" **nie renderuje się w danych demo** (`DemoSeeder`
nie ma ani jednego wpisu z `recipe_id`), więc skrypt pomiarowy sam dokłada taki
wpis i **przerywa z błędem**, jeśli na zmierzonej stronie paska nie znalazł.

### Świadomie nietknięte

`.post-card-tagi` **nie ma wcięcia bocznego** — chipsy dochodzą do krawędzi
karty. To usterka **pozioma**, nie ta zgłoszona. `.chipsy` (40 px)
i `.post-card-actions` (17 px) zmierzone: nie ma tam zera, raczej nadmiar —
wyrównywanie to zmiana wyglądu poza zgłoszeniem, na komponencie używanym też
w wyszukiwaniu i na szynie profilu.

📄 `resources/css/app.css` · `scripts/odstepy-karty-wpisu.mjs` ·
`tests/Feature/OdstepPodZdjeciemNaKarcieWpisuTest.php` · D-154 · D-099 · D-106
