---
name: kuking-ekran
description: Dodaje nowy ekran (stronę) do Kuking.pl zgodnie z twardym standardem UX 50+ — trasa, kontroler, widok Blade, testy i checklista dostępności. Użyj, gdy trzeba stworzyć nową stronę, formularz albo widok w tym repozytorium.
---

# Nowy ekran w Kuking

Ta procedura istnieje po to, żeby każdy nowy ekran spełniał standard z
`docs/UX_50_PLUS.md` **od pierwszego commita**, a nie po review.

## Kolejność

1. **Trasa** w `routes/web.php`.
   - Adres po polsku (`/przepisy`, `/zeszyt`, `/ustawienia`), chyba że to
     `/home`, `/login` albo `/register`.
   - Limit zapytań z `config('kuking.limits')`, nie liczba wpisana w trasie.
   - Trasy publiczne nad `/@{username}` — inaczej profil je przechwyci.

2. **Kontroler** — cienki. Waliduje, woła akcję z `app/Domain`, zwraca widok.
   - Wszystkie komunikaty walidacji **po polsku i mówiące, co zrobić**.
     Źle: „The body field is required.” Dobrze: „Napisz coś, zanim wyślesz komentarz.”
   - Jeśli ekran dotyka cudzej treści: `$this->authorize(...)`. Zawsze.

3. **Widok** w `resources/views/pages/…`, w komponencie `<x-layout>`.
   Używaj gotowych komponentów zamiast pisać HTML od zera:
   `<x-field>`, `<x-error-summary>`, `<x-empty-state>`, `<x-confirm-button>`,
   `<x-post-card>`, `<x-recipe-card>`, `<x-cooked-card>`, `<x-comment-thread>`,
   `<x-avatar>`, `<x-photo>`, `<x-show-more>`.

4. **Testy** w `tests/Feature/`. Minimum: kto widzi, kto nie widzi,
   co się dzieje przy błędnych danych i czy poprawne dane nie giną.

5. **Dokumentacja** — jeśli ekran wchodzi do mapy ekranów, dopisz go
   do `docs/FLOWS_AND_SCREENS.md`.

## Teksty

Każdy tekst na ekranie bierzesz z `docs/brand/COPY_STYLE.md` — tam są gotowe
napisy dla większości sytuacji. Trzy reguły, o których najłatwiej zapomnieć:

- `kuKING` **maksymalnie raz na ekran**, nigdy w błędzie ani w moderacji;
- komunikat błędu mówi **co zrobić**, nie co się stało;
- zero emoji w tekście, najwyżej jeden wykrzyknik.

## Checklista przed commitem

- [ ] Tekst podstawowy ≥ 18 px (klasy z `tokens.css`, nie wartości na sztywno).
- [ ] Ważne przyciski ≥ 48 px wysokości (`.btn`).
- [ ] Każda ważna akcja ma **tekst**, nie samą ikonę.
- [ ] Nic nie wymaga hover, swipe ani long-pressa.
- [ ] Etykieta każdego pola jest widoczna (nie placeholder).
- [ ] Błąd przy polu **oraz** `<x-error-summary>` na górze.
- [ ] Formularz używa `old()` — poprawne dane nie znikają.
- [ ] Akcja destrukcyjna: `<x-confirm-button>` w bloku `.danger-zone`.
- [ ] Ekran działa **z wyłączonym JavaScriptem**.
- [ ] Strona prywatna ma `:noindex="true"` w `<x-layout>`.
- [ ] Widoczny focus przy nawigacji Tabem (nie nadpisuj `:focus-visible`).
- [ ] Sprawdzone przy 200% powiększenia i przy szerokości 320 px.
- [ ] Teksty zgodne z `docs/brand/COPY_STYLE.md` (lista kontrolna w sekcji 7).

## Pułapka Blade, na którą się tu nadziano

Dyrektywa Blade sklejona z poprzednim znakiem (`Tekst@if(...)`) **nie jest
rozpoznawana jako dyrektywa**, a domykający `@endif` już tak — co daje błąd
„unexpected token else”. Zawsze zostawiaj spację albo nową linię przed `@if`.

## Weryfikacja

```bash
vendor/bin/pint
php artisan test
npm run build
```

W worktree gita z dowiązanym `vendor` każda komenda artisana potrzebuje
`APP_BASE_PATH=$(pwd)` — bez tego Laravel ładuje trasy i klasy z głównego
katalogu, a testy są fałszywie zielone.
