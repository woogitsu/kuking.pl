> **Aktualizacja v3.1 · 7 września 2026.** Zmiany z audytu opisuje [AUDYT-V3.1.md](../AUDYT-V3.1.md). W sprawach motywu, stopki, pozycjonowania nagłówka i skali 200% ten dokument oraz `07-wdrozenie/MOTYW-V3.1.md` mają pierwszeństwo przed poniższym opisem v3.0. Paleta i znak pozostają bez zmian.

# Typografia

Rozmiar podstawowy to **18 px**, nie 16. To nie jest preferencja, tylko decyzja
podjęta dla tego odbiorcy i zapisana w `UX_50_PLUS.md`. Powrót do 16 wymaga
uzasadnienia mocniejszego niż „tak jest w innych serwisach”.

---

## 1. Skala

| Token | Rozmiar | Interlinia | Gdzie |
|---|---|---|---|
| `--text-meta` | 15 px | 1.4 | plakietka cicha, wiersz metadanych karty — **tylko z zastrzeżeniem z §4** |
| `--text-help` | 16 px | 1.5 | pomoc pod polem, podpis w dolnym pasku, stopka |
| `--text-body` | **18 px** | 1.55 | tekst podstawowy, etykieta pola, napis na przycisku |
| `--text-body-lg` | 20 px | 1.55 | treść wpisu, opis przepisu, tytuł modułu szyny |
| `--text-lead` | 22 px | 1.55 | zajawka, pierwsze zdanie przepisu, wpis bez zdjęcia |
| `--text-title-sm` | 24 px | 1.3 | tytuł karty wpisu, tytuł sekcji, wordmark |
| `--text-title` | 28 px | 1.25 | tytuł strony, tytuł przepisu na telefonie |
| `--text-title-lg` | 36 px | 1.25 | tytuł przepisu na komputerze (`clamp`) |
| `--text-title-xl` | 48 px | 1.1 | **wyłącznie** hero strony powitalnej (`clamp`) |

Skala nie jest geometryczna i to jest celowe. Krok 15 → 16 → 18 jest mały, bo
te trzy rozmiary występują obok siebie w jednej karcie i różnica ma być
wyczuwalna, ale nie ma robić schodów. Krok 24 → 28 → 36 jest duży, bo te trzy
nigdy nie występują razem.

---

## 2. Hierarchia karty — to tu rozstrzyga się, czy strona wygląda schludnie

Dzisiejszy problem: nazwa autora, plakietka, data i treść mają podobny rozmiar
i grubość, więc jedynym mocnym akcentem zostaje kolor — i wszystko, co
pomarańczowe, krzyczy jednakowo.

Nowy porządek, od najmocniejszego:

```
tytuł wpisu          24 px / 800   ← tu siada oko
treść wpisu          20 px / 400
nazwa autora         18 px / 700
metadane, plakietki  15 px / 600, kolor ink-muted
```

Cztery poziomy, cztery różne rozmiary, dwie różne wagi. Kolor marki nie bierze
w tym udziału **w ogóle** — jest zarezerwowany dla akcji.

**Tytuł wpisu jest nowym elementem.** Dziś karta go nie ma i dlatego największym
napisem w karcie jest nazwa autora — przez co strumień wygląda na listę ludzi,
a nie na listę dań.

---

## 3. Długość wiersza

Kolumna treści ma 45rem (720 px), co przy 18–20 px daje **65–75 znaków**. To jest
górna granica wygodnego czytania, nie cel do przekroczenia.

Długi dokument bez zdjęć (polityka prywatności, regulamin, zasady) czyta się
w `--container-czytanie` = 38rem, czyli około 65 znaków. Powód: w strumieniu
wiersz jest łamany co kilka akapitów przez zdjęcie, a w dokumencie prawnym nie
ma żadnej przerwy i 75 znaków przez cztery ekrany męczy.

---

## 4. Zastrzeżenie do rozmiaru 15 px — przeczytaj, zanim go użyjesz

`--text-meta` jest **jedynym rozmiarem poniżej 16 px w całym systemie** i istnieje
wyłącznie dlatego, że plakietka „konto przykładowe” musiała zejść z pierwszego
planu (decyzja D-103), a przy 16 px dalej konkurowała z nazwą autora.

Wolno go użyć tylko wtedy, gdy spełnione są **wszystkie trzy** warunki:

1. tekst obok, w tym samym bloku, ma **co najmniej 18 px**;
2. informacja w nim zawarta jest **powtarzalna i drugorzędna** (data, „konto
   przykładowe”, „3 komentarze”);
3. utrata tej informacji **nie blokuje żadnej czynności** — nikt nie musi jej
   przeczytać, żeby czegokolwiek dokonać.

Nigdy: etykieta pola, komunikat błędu, napis na przycisku, pomoc kontekstowa,
tekst prawny, jakikolwiek tekst stojący samotnie.

Przy skali 90% ten rozmiar schodzi do 13.5 px. To jest świadoma cena i granica —
dlatego wszystkie trzy warunki muszą być spełnione naraz.

---

## 5. Skala tekstu użytkownika

Ustawienie konta, atrybut na `<html>`:

```html
<html lang="pl" data-text-scale="125">
```

Kroki: 90, 100, 112, 125, 140 (dziś maksimum w bazie), 150 (arkusz jest gotowy).

Wszystkie tokeny typografii są zapisane jako `calc(<baza> * var(--user-text-scale))`,
więc skaluje się **wyłącznie tekst**:

- **nie** odstępy — gdyby się skalowały, przy 150% przyciski przestałyby się
  mieścić w wierszu;
- **nie** promienie i **nie** szerokości kontenerów;
- **nie** wysokości kontrolek — 48 px zostaje 48 px, a przycisk po prostu robi
  się wyższy, gdy napis zawinie się na dwa wiersze. Stąd bezwzględna reguła:
  **żaden element zawierający tekst nie ma `height:` na sztywno, tylko
  `min-height` i padding.**

Przy 150% układ ma prawo wyglądać gorzej. Nie ma prawa uciąć tekstu ani wywołać
przewijania w poziomie.

---

## 6. Font

`"Inter Variable"` wgrany lokalnie, z pełnym stosem systemowym jako zapasem:

```css
--font-sans: "Inter Variable", -apple-system, BlinkMacSystemFont, "Segoe UI",
  Roboto, "Noto Sans", "Liberation Sans", Arial, sans-serif;
```

Stos zapasowy nie jest ozdobą: przy `font-display: swap` człowiek czyta tekst
fontem systemowym, zanim webfont dojedzie — więc zapas musi być prawdziwym
stosem, a nie samym `sans-serif`.

Wszystkie wymienione fonty mają komplet polskich znaków (ą, ć, ę, ł, ń, ó, ś, ź, ż).

**Do rozstrzygnięcia po becie:** `Atkinson Hyperlegible` (Braille Institute),
zaprojektowany pod niską ostrość wzroku, wyraźnie odróżnia l/I/1 i O/0.
Nie wchodzi do wersji startowej, bo to kolejny webfont. `DESIGN_SYSTEM.md` §2.2
przewiduje test A/B, jeśli badania z osobami 60+ pokażą problem z myleniem liter.

---

## 7. Wagi

Trzy, nie siedem:

| Waga | Gdzie |
|---|---|
| 400 | tekst ciągły, treść wpisu, opis |
| 600–700 | etykiety, nazwa autora, przyciski wtórne i ciche, metadane |
| 800 | tytuły, przyciski główne, bieżąca pozycja nawigacji |

Czego nie robimy: kursywy w interfejsie (źle się skaluje i gorzej czyta),
wersalików poza wordmarkiem `KUKING`, rozstrzelenia liter w tekście ciągłym.
