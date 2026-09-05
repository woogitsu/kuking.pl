# UX 50+ — twardy standard Kuking

Produkt nie jest oznaczany jako „dla seniorów”. Ma być po prostu łatwy.

## Typografia

| Element | Standard |
|---|---|
| body | 18 px minimum |
| większy tekst | 20–22 px |
| input | minimum 18 px |
| line-height | 1.5–1.65 |
| długość wiersza | ok. 55–75 znaków |
| ważny tytuł | 28–36 px |

## Kontrolki

Produktowa reguła:

- ważne buttony min. 48 px wysokości;
- duży odstęp między akcją normalną i destrukcyjną;
- ważna akcja ma tekst.

Dobre:
`[ Zapisz ] [ Komentuj ] [ Ugotowałem ]`

Słabe:
`♡  ⋮  ↗` bez podpisów.

## Nawigacja mobile

Maks. 5 głównych pozycji:

```text
Start | Szukaj | Dodaj | Moje | Profil
```

## Bez ukrytych gestów

Żadna ważna funkcja nie wymaga:

- hover;
- swipe;
- long press;
- gestu od krawędzi;
- domyślenia się znaczenia ikonki.

## Dodanie wpisu

Jedna strona:

```text
Wybierz zdjęcie
Napisz kilka słów
Kto widzi
Opublikuj
```

## Przepis

3 kroki z wyraźnym:
- „Krok 1 z 3”;
- Wstecz;
- Dalej;
- Zapisz szkic.

## Błędy

Nie:
> 422 Unprocessable Entity

Tak:
> Nie udało się dodać zdjęcia, ponieważ plik ma ponad 15 MB. Wybierz mniejsze zdjęcie.

Błąd przy polu + podsumowanie. Poprawne dane nie znikają.

## Autosave

Użytkownik widzi:
> Szkic zapisany.

## Destrukcja

Usunięcie wymaga jasnego potwierdzenia. Preferować soft delete / możliwość odzyskania, jeśli pasuje do polityki.

## Accessibility

Cel WCAG 2.2 AA.

Testy:
- klawiatura;
- widoczny focus;
- 200% zoom;
- 320 px;
- większy font;
- screen reader smoke;
- Windows High Contrast.

WCAG 2.2 ma minimum target size 24 CSS px w określonych warunkach. Kuking stosuje wygodniejszą produktową regułę 48 px dla głównych kontrolek.

## Desktop

Nie projektować tylko mobile.

Desktop:
- centralna kolumna;
- maks. 2 główne kolumny;
- duże zdjęcia;
- brak ściany miniaturowych kart.

## Onboarding

1. zainteresowania;
2. opcjonalnie obserwuj ludzi;
3. pokaż kilka treści;
4. gotowe.

Na końcu:
`[ Dodaj pierwsze zdjęcie ]`
oraz
`Na razie tylko pooglądam`.

Nie wymuszać publikacji.

## Testy z użytkownikami

Przed publiczną betą:
- 5 osób 50–59;
- 5 osób 60–69;
- 3 osoby 70+;
- Android, iPhone i desktop.

Scenariusze:
- konto;
- zdjęcie;
- przepis;
- wyszukiwanie;
- zapis;
- komentarz;
- zwiększenie tekstu;
- usunięcie wpisu.

Mierzyć przede wszystkim miejsca, w których pada pytanie:
> „Co mam teraz kliknąć?”
