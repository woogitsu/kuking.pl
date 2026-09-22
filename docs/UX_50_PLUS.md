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

### Jeden nazwany wyjątek: menu „więcej" na karcie wpisu

Powyższe „słabe" ma **jeden** wyjątek i jest nim menu „więcej" na karcie wpisu
(`components/post-card.blade.php`, `<details class="post-card-menu">`). Ten
przycisk to same trzy kropki, bez widocznego napisu — na świadomą decyzję
właściciela z 12 września 2026, odwracającą decyzję z 11 września.

**Dlaczego akurat ten jeden.** To nie jest ustępstwo estetyczne, tylko
rachunek rozpoznawalności. Nasi użytkownicy przyszli z Facebooka i spędzili
tam lata; trzy kropki w rogu wpisu są dla nich znakiem już znanym, a nie
ikoną do rozszyfrowania. Reguła ogólna mówi o ikonie, której trzeba się
**domyślić** — tutaj domyślania nie ma.

**Gdzie ten wyjątek się kończy.** Na tym jednym przycisku. Pasek akcji pod
wpisem („Ugotowałem", „Napisz komentarz", „Zapisuję"), paski nawigacji,
przyciski zamykania, akcje moderacyjne i wszystko inne w serwisie dalej mają
tekst przy ikonie. Wyjątek nie jest wzorcem do naśladowania i nie rozciąga
się przez podobieństwo („to też jest menu").

**Czego wyjątek NIE zmienia:**

| | |
|---|---|
| nazwa dostępna | zostaje — `aria-label="Więcej przy tym wpisie"`, jedyne, co ma czytnik ekranu |
| cel dotknięcia | zostaje 48 × 48 px — znikł napis, nie przycisk |
| bez JavaScriptu | zostaje — menu to `<details>`, otwiera się bez skryptu |
| kształt kropek | rysuje komponent `<x-ikona nazwa="more">`, nie znak `···` z klawiatury |

Pilnuje tego test
`KartaWpisuTest::test_menu_karty_to_same_kropki_ale_czytnik_ekranu_nie_traci_nic`.
Pełne uzasadnienie i granica: `AGENTS.md` §5.

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

### Pięć kryteriów, które musi spełnić KAŻDY komunikat walidacji

Zdanie „po polsku" to za mało. Sama treść komunikatu przechodzi, gdy spełnia
wszystkie trzy warunki naraz:

1. **jest po polsku** — żadnego „The :attribute field is required" ani surowego
   klucza tłumaczenia w rodzaju `validation.min.numeric`;
2. **nazywa pole słowem, które człowiek WIDZI na ekranie** — nie nazwą kolumny
   (`display_name`, `hero_media_id`) i nie nazwą wymyśloną na potrzeby pliku
   językowego. Jeśli legenda brzmi „Kto ma to widzieć?", komunikat nie ma prawa
   mówić o polu „widoczność";
3. **mówi, CO ZROBIĆ** — „Pole «tytuł» jest wymagane" i „To pole nie może być
   puste" mówią tylko, co jest źle. „Wpisz nazwę dania — choćby «obiad»" mówi,
   co zrobić.

Do tego dwie rzeczy wokół samego zdania:

4. **błąd wskazuje POLE, którego dotyczy** — `aria-invalid` i `aria-describedby`
   na polu albo na grupie wyboru, a odnośnik z podsumowania prowadzi do
   ISTNIEJĄCEJ kotwicy `#f-<nazwa pola>`. Pola z `x-field` dostają to same;
   grupy `radio`/`checkbox` i pola plikowe trzeba opisać ręcznie — wzorzec
   i uzasadnienie w `resources/views/components/blad-grupy.blade.php`;
5. **poprawne dane nie znikają** — także w grupach wyboru. `checked` wpisane
   na sztywno zamiast `old()` cicho zmieniało widoczność zakładanego zeszytu
   z „Wszyscy" na „Tylko ja" po każdej nieudanej walidacji.

Wszystkich pięciu punktów pilnuje `tests/Feature/BledyMowiaCoZrobicTest.php`
— i pilnuje ich na komunikatach WYZWOLONYCH prawdziwym żądaniem, nie na treści
plików w `lang/`. Komunikat, który leży w pliku językowym, ale nigdy nie wypada
na ekran, nie jest komunikatem produktu.

### Strony błędów

`resources/views/errors/` — po polsku, w layoucie serwisu, każda mówi **co zrobić**
i daje drogę powrotu. Kod (404, 419, 500…) zostaje w statusie HTTP, ale nie jest
treścią ekranu — dla człowieka nie znaczy nic.

Wyjątkiem są **500 i 503**: te nie używają `x-layout`, bo layout pyta bazę
(`auth()->user()`, licznik powiadomień), a strona awarii nie może zależeć od tego,
co właśnie padło. Mają własny, samowystarczalny szkielet: `errors/_prosty.blade.php`.

### Wygasła sesja (419) nie kasuje wpisanego tekstu

Po odbiciu tokenu CSRF Kuking **wystawia ten sam formularz jeszcze raz**, z treścią
z odbitego żądania i ze świeżym tokenem. Jedno kliknięcie „Wyślij jeszcze raz”
i wpis idzie tam, gdzie miał iść. Działa bez JavaScriptu.

Ochrona CSRF zostaje nietknięta — ponowne wysłanie idzie przez nią normalnie,
a żądanie bez tokenu dalej kończy się na 419. Hasła i pola wrażliwe nie wracają.
Uzasadnienie wyboru (i odrzucenia `back()->withInput()`): `App\Exceptions\OdzyskanyFormularz`.

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

**Zestaw do przeprowadzenia tych sesji** — skrypt prowadzącego, osiem zadań R1,
kryteria sukcesu, karta notatek bez nagrań, definicja blokera i sposób policzenia wyniku —
leży w `docs/product/TESTY_Z_UZYTKOWNIKAMI.md` (issue #15). Wnioski z badania
wracają TUTAJ: ten standard ma się uczyć, a nie zostać na zawsze hipotezą.
