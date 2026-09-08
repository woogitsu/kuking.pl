# Ruch, stany i to, co się dzieje po kliknięciu

## 1. Ruch

Minimalny, funkcjonalny, nigdy dekoracyjny.

| Co | Czas | Krzywa |
|---|---|---|
| zmiana tła przycisku, obwódki pola | `--czas-szybki` 150 ms | `ease-out` |
| pojawienie się komunikatu, cień karty | `--czas-zwykly` 200 ms | `ease-out` |

Wciśnięcie przycisku przesuwa go o **1 px w dół**. Tyle wystarczy, żeby palec
dostał potwierdzenie, i za mało, żeby układ drgnął.

**Czego nie ma i nie będzie:** karuzel automatycznych, parallaksu, animowanych
wejść treści przy przewijaniu, efektów przy najechaniu, które zmieniają rozmiar
elementu, „skeletonów”, które pulsują dłużej niż sekundę.

`prefers-reduced-motion: reduce` wyłącza wszystkie przejścia globalnie. To nie
jest uprzejmość — dla części osób ruch na ekranie wywołuje realne dolegliwości.

---

## 2. Sześć stanów każdej kontrolki

Każdy komponent interaktywny ma zdefiniowane sześć stanów. Brak któregokolwiek
jest błędem, nie uproszczeniem.

| Stan | Jak wygląda | Zasada |
|---|---|---|
| **spoczynek** | stan podstawowy | — |
| **najechanie** | zmiana tła o jeden stopień; nigdy zmiana rozmiaru | dotyczy tylko myszy — nie może być jedyną drogą do niczego |
| **fokus** | pierścień 3 px `--color-focus`, offset 2 px; na kolorowym tle technika halo | **`:focus-visible`, nigdy gołe `:focus`** — pierścień przy Tab tak, przy kliknięciu myszą nie |
| **wciśnięty** | ciemniejszy odcień + 1 px w dół | — |
| **wyłączony** | `opacity .55`, kursor `not-allowed` | **zawsze z wyjaśnieniem obok** — patrz §4 |
| **błąd** | ramka `--color-danger` 3 px + komunikat pod spodem + ikona | kolor nigdy sam |

`outline: none` bez pełnoprawnego zamiennika jest zakazane bez wyjątku.

---

## 3. Bez JavaScriptu — co to znaczy w praktyce

Cała nawigacja i formularz przepisu muszą działać przy wyłączonym skrypcie.
To wyklucza część wzorców i **wymusza lepsze** w ich miejsce:

| Zamiast | Robimy |
|---|---|
| panel rozwijany | osobna strona albo sekcja zawsze widoczna |
| modal potwierdzenia | osobna strona „Na pewno usunąć?” z dwoma przyciskami |
| kreator w jednym widoku z zakładkami | trzy adresy, trzy strony, trzy zapisy szkicu (D-108) |
| lista rozwijana „Kto to widzi” | trzy duże karty, zawsze widoczne |
| natywny przycisk wyboru pliku | `<label for>` na ukrytym inpucie (D-107) |
| przewijanie w nieskończoność | przycisk „Pokaż więcej” z zachowaną pozycją |
| podpowiedź po najechaniu | tekst pod polem, stały, nieznikający |

Jedyny `<script>` w całym systemie to blok `application/ld+json` na stronie
przepisu — to dane dla wyszukiwarki, nie kod.

---

## 4. Wyłączony przycisk zawsze mówi dlaczego

Reguła „nigdy” nr 6. Cichy, niewyjaśniony `disabled` jest w tym produkcie
usterką: człowiek naciska, nic się nie dzieje, i nie ma jak się dowiedzieć, co
zrobić.

```html
<button class="btn btn-primary" disabled>Opublikuj</button>
<p class="btn-wyjasnienie">Dodaj zdjęcie albo napisz kilka słów, żeby opublikować.</p>
```

Lepiej jednak, jeśli przycisk **nie jest wyłączony**, tylko po naciśnięciu
pokazuje, czego brakuje — bo wtedy człowiek dostaje odpowiedź, a nie ścianę.

---

## 5. Błąd: co się stało → dlaczego → co zrobić

Ta kolejność jest obowiązkowa. Nigdy kod HTTP, nigdy „Błąd walidacji”.

Po nieudanym zapisie formularza:

1. **Podsumowanie na górze** — „Jednej rzeczy jeszcze brakuje” albo „Kilku rzeczy
   jeszcze brakuje”, z listą linków prowadzących do pól. `role="alert"`, fokus
   przenosi się na nagłówek podsumowania.
2. **Komunikat przy każdym błędnym polu** — pod polem, z ikoną i tekstem,
   `aria-invalid="true"` i `aria-describedby`.
3. **Poprawne dane nigdy nie znikają.**

Linki w podsumowaniu to zwykłe `#id` — działają bez skryptu. Żeby po skoku było
widać, do którego pola się trafiło, arkusz rysuje na nim tę samą obwódkę co przy
fokusie (`:target`).

Wygasła sesja (419) nie kasuje wpisanego tekstu: serwis wystawia ten sam
formularz jeszcze raz, z treścią i ze świeżym tokenem. Ochrona CSRF zostaje
nietknięta.

---

## 6. Co czyta czytnik ekranu, kiedy coś się zmienia

| Zdarzenie | Sposób |
|---|---|
| „Szkic zapisany.” | `aria-live="polite"` — nie przerywa tego, co człowiek robi |
| błąd zapisu | `role="alert"` — przerywa, bo trzeba zareagować |
| „Załadowano 10 kolejnych wpisów” | `aria-live="polite"` po „Pokaż więcej” |
| bieżąca pozycja nawigacji | `aria-current="page"` |
| bieżący krok kreatora | `aria-current="step"` |
| przełącznik „Obserwuj / Obserwujesz” | zmienia **napis**, nie tylko kolor |
| postęp wysyłania zdjęcia | `role="progressbar"` + tekst „Wysyłanie 42%” |

Zapis nazwy `kuKING` dostaje `aria-label="kuking"` — część czytników literuje
wersaliki w środku wyrazu („ku-ka-i-en-gie”) i zamienia nazwę w bełkot.

---

## 7. Rzeczy nieodwracalne

- odstęp między akcją zwykłą a destrukcyjną: minimum 32 px albo osobna sekcja
  z linią i nagłówkiem;
- potwierdzenie zawsze na osobnej stronie, z pełnym zdaniem, co się stanie:
  „Na pewno usunąć ten przepis? Wykonania i komentarze innych osób też przestaną
  być widoczne.”;
- usunięcie konta: 30 dni na zmianę zdania, a przed nim propozycja pobrania
  swoich danych;
- żadnego „decyzja jest ostateczna” bez podanej drogi odwoławczej.
