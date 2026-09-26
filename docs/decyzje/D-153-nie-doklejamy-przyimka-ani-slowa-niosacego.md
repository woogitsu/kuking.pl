## D-153 · Nie doklejamy przyimka ani słowa niosącego przypadek do cudzego tekstu ani do nazwy konta

**Data:** 11 września 2026 · Zgłosił właściciel · PR #397 · Status: **obowiązuje**

### Zgłoszenie

> „czemu źródło przepisu ma «po» przed źródłem?"

Na zrzucie: sekcja **„Skąd ten przepis"**, a pod nią zdanie **„Po Nasze smaki."**

### Zasada

> **Nigdy nie doklejaj przyimka ani słowa niosącego przypadek do tekstu
> wpisanego przez człowieka ani do nazwy wyświetlanej konta.** Polskiej odmiany
> nie da się policzyć z dowolnego ciągu znaków, a każda próba kończy się
> zdaniem, które wygląda na zepsute oprogramowanie.

### Co było zepsute — sprawdzone przy plikach, nie domyślone

| Miejsce | Wejście | Co widział człowiek |
|---|---|---|
| `pages/recipes/show.blade.php` | `Nasze smaki` | **Po Nasze smaki.** |
| `pages/recipes/show.blade.php` | `po mamie` | **Po po mamie.** |
| `components/recipe-wizard.blade.php` (podgląd) | to samo | to samo |
| `app/Models/Recipe.php` `attributionLine()` | `Nasze smaki` + konto `Krzysztof` | **przepis Nasze smaki, spisany przez Krzysztof** — dwa błędy odmiany naraz |
| to samo, bez źródła | konto `Krzysztof` | **przepis Krzysztof** |
| formularze | — | etykieta „Po kim ten przepis", podpowiedź `po mamie, Halinie` — **formularz prosił o formę, której widok i tak nie umiał użyć** |

### Rozwiązanie idzie w PYTANIE, nie w mechanizm odmiany

1. **Pole pyta o frazę, która stoi samodzielnie.** „Po kim ten przepis" →
   **„Od kogo albo skąd masz ten przepis"**, podpowiedź
   `od mamy · z gazety · z bloga Nasze smaki`. Odpowiedź na **to** pytanie
   działa i sama („Od mamy."), i po słowie „przepis".
2. **Widok pokazuje wartość dosłownie**, pod nagłówkiem „Skąd ten przepis" —
   nagłówek niósł to znaczenie od początku, przyimek był powtórzeniem. Pierwsza
   litera przez `Str::ucfirst()` (wielobajtowe), więc „od mamy" wygląda jak
   zdanie także przy „ó", „ż", „ś". **Kropki nie doklejamy** — przy wpisanej
   wyszłyby dwie.
3. **Podpis nie odmienia niczego.** Autor zostaje w linii, ale **w mianowniku,
   w osobnym członie po „·"**, nigdy po „przez":

```text
ze źródłem:  {nazwa konta} · skąd ten przepis: {wartość pola}
bez źródła:  {nazwa konta}
```

Dwukropek zdejmuje wymaganie przypadku, więc „od mamy", „Nasze smaki" i
„z gazety Przyjaciółka" działają jednakowo.

### Czego świadomie nie zrobiono: migracji danych

Kto wpisał „po mamie" pod starym pytaniem, zobaczy „Po mamie" — czyli
poprawniej niż „Po po mamie." Kto wpisał samo imię, zobaczy „Halina" pod
nagłówkiem „Skąd ten przepis" zamiast „Po Halina." **Żadna automatyczna zamiana
wolnego tekstu nie jest bezpieczna — a to jest dokładnie ta sama pułapka,
o którą chodzi w całej tej decyzji.**

### Test na wrogich danych

`tests/Feature/ZrodloPrzepisuBezPrzyimkaTest.php` — **5 testów, 135 asercji** —
chodzi na `od mamy`, `Nasze smaki`, `z gazety Przyjaciółka`, `Halina` oraz na
dwóch nazwach kont: `Krzysztof` i `Żaneta` (odmienia się inaczej niż męskie
imię). Kontrola ujemna wykonana naprawdę: po przywróceniu starego kodu **4 z 5**
testów czerwone; piąty (o pytaniu w formularzu) przy tym sabotażu przechodził,
bo go nie dotyczył, więc dostał **własny** sabotaż — starą etykietę — i wtedy
też oblał.

📄 `app/Models/Recipe.php` · `docs/brand/COPY_STYLE.md` · `docs/product/SOUL.md` ·
`tests/Feature/ZrodloPrzepisuBezPrzyimkaTest.php`
