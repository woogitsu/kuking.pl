# Propozycja: prywatna kolejka kilku przepisów z niezależnymi minutnikami (#2379)

Status: **propozycja do decyzji właściciela, nic nie zbudowano.** Po odpowiedziach na pytania z części 4
można budować w jednym PR-ze.

## 1. Co już jest (stan repo)

- Tryb gotowania jednego przepisu: `/przepisy/{przepis}/gotuj`, `CookingModeController`, widok
  `resources/views/pages/recipes/cooking.blade.php`. Każdy krok to osobne przeładowanie strony (GET/POST+redirect).
- Minutnik kroku: `resources/js/minutnik-krok.js` (zegar monotoniczny, #751). Stan w `sessionStorage` karty,
  klucz `kuking.minutnik.{slug}.{krok}` — **już dziś osobny dla każdego przepisu i kroku** (#740). Alarmy z innych
  kroków (#1301) i odtwarzanie po przeładowaniu (#1302) istnieją. Nazwa dostępna „Pozostały czas” (#492).
- Postęp na koncie, opcjonalny (#2016): `cooking_progress`, `PostepGotowania`, wygasa po 24 h. Zapisuje odhaczone kroki,
  składniki i porcje. **Minutniki celowo NIE są synchronizowane między urządzeniami** (D-wiersz „#2016: minutniki”
  w DECISIONS.md).
- Nie ma: kolejki wielu przepisów, wspólnego ekranu, przełącznika między przepisami.
- Zakazy: #2379 nie jest ani na liście „V2, ale nie teraz”, ani w „Nie wcześnie” (FEATURES.md); D-282 pozwala budować V2.
  Nie dotyczy go też #1906 (głos) ani #2037 (planer).

## 2. Rekomendowany zakres (najmniejsza kompletna wersja)

1. **Kolejka jest prywatna i lokalna dla przeglądarki** (`localStorage`, lista slugów + kolejność). Bez nowej tabeli,
   bez migracji, bez synchronizacji minutników (zgodnie z #2016). Konto tylko przez istniejący, świadomy przełącznik
   synchronizacji postępu — on dalej obsługuje odhaczone kroki każdego przepisu osobno.
2. **Ekran `/gotuj-kilka`** (GET, bez JS pokazuje listę kolejki jako zwykłe linki „Gotuj: {tytuł}” do trybu pojedynczego,
   więc bez martwego przycisku — D-053). Z JS: pasek przełącznika potraw (przyciski ≥ 48 px, nazwane, np. „Zupa —
   krok 2 z 5”), „Przesuń wyżej / niżej”, „Usuń z kolejki”. Bez przeciągania, hover i swipe.
3. **Dodawanie:** przycisk „Dodaj do kolejki gotowania” na stronie przepisu i w trybie gotowania; min. 2, maks. patrz pytanie 1.
4. **Autoryzacja:** na serwerze każde wejście przez `RecipePolicy::view` (UUID/slug to nie autoryzacja). Przepis, którego
   osoba już nie może zobaczyć (usunięty, prywatny, ukryty), wypada z kolejki z komunikatem po polsku. Treść przepisu
   nigdy nie jest kopiowana do kolejki — tylko identyfikator.
5. **Minutniki:** wspólny licznik pokazuje wszystkie aktywne minutniki wszystkich potraw z nazwą potrawy
   („Zupa: 4:12”); każdy ma własny klucz `kuking.minutnik.{slug}.{krok}` (już istnieje) i własny przycisk „Anuluj”.
6. **Czyszczenie sesji:** „Wyczyść kolejkę” (z potwierdzeniem), automatyczne wygaszenie po 24 h od ostatniej zmiany
   (jak postęp na koncie), wylogowanie czyści kolejkę i minutniki z tej przeglądarki.
7. **Tryb jednego przepisu bez zmian** — kolejka jest dodatkiem, brak kolejki = dzisiejsze zachowanie.
8. **AI:** poza pierwszą wersją. Gdy właściciel zechce: tylko na żądanie, podpowiedź kolejności z czasów, bez zapisu
   i bez zmiany przepisu (osobne zadanie, osobna zgoda w rozumieniu polityki AI).
9. Testy: kolejność, dodanie/usunięcie ≥ 2, limit, autoryzacja (widoczność przepisu), wygaszanie, brak martwego
   przycisku bez JS, 320 px/200% (test strażnika CSS/E2E), test JS niezależności kluczy minutników A i B.

## 3. Rekomendacje do pytań

| # | Pytanie | Rekomendacja | Uzasadnienie |
|---|---------|--------------|--------------|
| 1 | Limit przepisów w kolejce | **4** | Obiad „zupa + drugie + surówka + deser”; więcej nie mieści się na 320 px bez przytłoczenia 50+ |
| 2 | Gdzie trzymać kolejkę | **Tylko przeglądarka** (`localStorage`), bez konta | Zgodne z prywatnością i #2016 (minutniki lokalne); zero migracji |
| 3 | Po zamknięciu karty | Kolejka zostaje do 24 h; **działające minutniki giną** razem z `sessionStorage` karty | Zegar monotoniczny nie przeżywa karty; ukryte minutniki bez alarmu byłyby niebezpieczne |
| 4 | Dźwięk alarmu | Ten sam co w minutniku pojedynczym + komunikat tekstowy „Zupa: czas minął”; bez nowych dźwięków | Spójność, brak nowych zasobów |
| 5 | Powiadomienia systemowe | Nie | Wymagają zgody i osobnej decyzji (PWA/push) |
| 6 | Postęp kroków | Jak dziś, per przepis; przy włączonej synchronizacji dalej na koncie | Nic nowego do zapisania |
| 7 | „Ugotowałem” | Po ostatnim kroku danej potrawy, osobno dla każdej | Ugotowałem jest ważniejsze niż lajk (AGENTS.md); jeden wpis na przepis |
| 8 | AI | Odłożyć | Brak decyzji o AI na tym ekranie |

## 4. Pytania do właściciela (blokują budowę)

1. Limit przepisów w kolejce: 4 (rekomendacja), 3, 5 czy bez limitu?
2. Kolejka tylko w przeglądarce (rekomendacja) czy także na koncie? Konto wymaga migracji, polityki prywatności i
   rozstrzygnięcia konfliktu z „minutniki nie są synchronizowane” (#2016).
3. Czy akceptujesz, że zamknięcie karty kończy działające minutniki (kolejka zostaje)?
4. Czy AI-owa podpowiedź kolejności ma wejść w pierwszej wersji (rekomendacja: nie)?
5. Czy po akceptacji dopisać decyzję D-xxx do DECISIONS.md i pozycję do FEATURES.md (sekcja V2)?

## 5. Zakres PR po decyzji (szacunek)

Nowy kontroler `KolejkaGotowaniaController` (lista + walidacja widoczności przez Policy), widok Blade, moduł
`resources/js/kolejka-gotowania.js` + `.test.mjs`, przyciski dodania w `szczegoly` i `cooking`, testy Feature,
CHANGELOG `[nowa funkcja]`, akapit w `resources/nowosci/tresc.md`, zapis w `docs/FEATURES.md`. Bez migracji (wariant lokalny).
