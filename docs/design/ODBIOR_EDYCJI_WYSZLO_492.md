# Odbiór pełnej edycji przepisu i Wyszło — 14.09.2026

## Stan końcowy

Lokalny serwer8033, istniejąca sesja i prywatny opublikowany przepis `lokalny-szkic-odbioru-publikacji`. Bez ponownego setupu, nowej publikacji, testów PHP i zmian źródeł aplikacji. Przeglądarki zamknięto; serwer i fixture pozostawiono zadaniu głównemu.

Edycja zapisała poprawiony tytuł; pierwotną treść następnie przywrócono. GET Wyszło był dozwolony i odpowiedział200. Końcowa kontrola objęła wszystkie37 przystanków Tab edycji, trzy grupy radiowe oraz3 przystanki Wyszło. Pięć wysokich etykiet plików sprawdzono dodatkowo na10 obejrzanych rastrach i przez10 rzeczywistych otwarć wyboru pliku. Nie potwierdzono całkowitego ukrycia fokusu ani utraty obsługi klawiaturą. Pozostaje opisana niżej niedogodność przewijania; wynik nie jest pełnym audytem WCAG.

## Edycja, dane i przywrócenie

Rzeczywisty formularz odrzucił tytuł `Ab`. Po dopisaniu do pierwotnego tytułu „ — odbiór edycji” zapis i ponowne wejście pokazały nową wartość. Następnie przywrócono pierwotny tytuł przez ten sam formularz.

`before.json`, `after.json`, `restored.json` zawierają pola formularza bez CSRF. Jedyna różnica między before/restored to `steps[0][id]`. Wynika to z istniejącego `PublishRecipe::syncSteps` (`app/Domain/Recipes/Actions/PublishRecipe.php:613–621`): usuwa wiersze i tworzy je ponownie, zachowując powiązanie zdjęcia przez `stepMediaId`. UUID nie przywracano ręcznie; sama zmiana identyfikatora nie jest tu zgłoszeniem błędu.

Prywatność, składnik „1 litr wody”, przygotowanie i minutnik1 pozostały w formularzu. Istniejące zdjęcie kroku było widoczne po ponownym wejściu; obejrzano `edit-desktop-light.png`. Przywrócono treść formularza, nie metadane updated_at ani identyfikatory wierszy bazy.

**Ograniczenie dowodu walidacji:** pomocniczy skrypt zatrzymał się przy porównaniu UUID i nie zachował końcowego JSON całego przebiegu błędu/korekty. Zapisane trzy pliki pól dowodzą odczytanych wartości, nie są pełnym logiem HTTP walidacji. Wstępny błędny selektor `#error-summary` poprawiono na `.error-summary`; nie uznano problemu przyrządu za błąd aplikacji.

## Wyszło: uprawnienia i zakres

`CookedEventPolicy::celebrate` dopuszcza autora przepisu oraz sprawdza blokadę i dostępność autora wykonania (`app/Policies/CookedEventPolicy.php:185–195`). GET `/ugotowane/{id}/wyszlo` istniejącego własnego wykonania odpowiedział200. Nie obchodzono odmowy.

Nie wysyłano podziękowania. Ten odbiór nie dowodzi zapisu komentarza ani wysłania powiadomienia. GET tego ekranu może oznaczyć powiadomienie jako przeczytane; nie nazywamy go ogólnie operacją bez skutków ubocznych.

## Końcowy oracle klawiatury

Dwa ekrany × dwa motywy, tekst aplikacji140%, prawdziwy zoom2 przez `chrome.tabs.setZoom/getZoom`, rzeczywiste okno640×1887 i `viewport:null`. Odczyt układu: CSS320×900, scrollWidth320. Bez programowego ustawiania fokusu: świeży GET i rzeczywisty Tab od stanu początkowego przeglądarki.

| Ekran | Inwentaryzacja | Osiągnięte | Dodatkowa obsługa |
|---|---|---|---|
| Pełna edycja |44 aktywne elementy,37 przystanków po zgrupowaniu radiów po name|37/37 w obu motywach|difficulty3/3, visibility3/3, source_type4/4 przez ArrowRight; pełen obieg przywraca początkowy wybór|
| Wyszło |3 aktywne elementy|3/3 w obu motywach|Bez wysyłania formularza|

Wszystkie odwiedzone elementy miały `:focus-visible`. JSON `oracle-{edit,wyszlo}-{light,dark}.json` zapisuje inwentaryzację, odwiedzone identyfikatory, radia, rozmiary, outline/cień i hit-test; `missing` jest puste. Pozostałe32 przystanki edycji i3Wyszło miały dodatni hit-test środka każdego clientRect. Nie zastosowano automatycznie minimum48px do linków inline i natywnych radio, których cel obejmuje etykietę. Rozmiary zapisano; nie jest to uniwersalny wynik zgodności wszystkich celów dotykowych.

`desktop.json` zawiera kontrolę1440px, zoom1, tekst140%, oba motywy. `results.json` zachowuje wcześniejsze przejście; wynik kompletności należy czytać z końcowych plików oracle.

## Pięć wysokich etykiet: raster i wybór pliku

Reproduktor `files.mjs`, dowody `file-{2,20,35,38,41}-{light,dark}.json` i PNG. Obejrzano wszystkie10 rasterów wykonanych bezpośrednio po Tab. Minimalne odtworzenie: istniejący przepis → `/przepisy/{recipe}/edycja`, CSS320×900, tekst140%, prawdziwy zoom2, Tab do `hero_photo`, `source_scan`, `steps[0..2][photo]`.

W każdej próbie widoczny jest górny łuk i boczny fragment niebieskiego pierścienia. To outline3px na całej etykiecie, nie osobny pseudo-element; light rgb(21,94,239), dark rgb(110,168,255). Etykiety mają wysokość315,65 /393,77 /710,61px. Ich górne krawędzie po Tab znajdują się odpowiednio617,70 /617,85 /470,58 /656,63 /656,67px. Nagłówek kończy się około216px, dolna belka zaczyna około718px: pozostaje około502px ciągłego pasa.

Dla dwóch pustych zdjęć kroków widać około61px górnej części etykiety przed belką. Nazwa pola nad kartą, ikona oraz pierścień są widoczne; tekst akcji „Dodaj zdjęcie” jest początkowo przykryty. Przy kroku z istniejącym zdjęciem widać również „Zmień zdjęcie”. Hit-test próbek wewnątrz etykiety potwierdził widoczne fragmenty we wszystkich10próbach. Minimum/maksimum próbek w JSON nie oznacza ciągłego pasa: zaokrąglenia belki przepuszczają pojedyncze trafienia.

Rzeczywisty Enter po Tab wywołał10/10 zdarzeń filechooser, wszystkie single-file. Nie wybrano pliku; `setFiles([])` pozostawiło pola puste. Niczego nie wysłano ani nie wgrano. To potwierdzenie kontrolki przez automatyzację, nie ogląd natywnego okna na fizycznym telefonie.

**Interpretacja:** ujemny hit-test samego środka wysokiej karty nie dowodził całkowitego zasłonięcia fokusu. Końcowy raster potwierdza widoczny pierścień, a Enter działa. Praktyczna niedogodność należy do rodziny #530: wysoki obszar wyboru obejmuje opis, dlatego tekst akcji wymaga przewijania. W zdjęciach kroków jest silniejsza (710,61px zamiast315,65px), bo węższa kolumna mieści więcej tekstu. Nie potwierdzono całkowitego ukrycia ani utraty dostępu; nie wyprowadzamy z tego pełnego PASS WCAG.

## Ogląd i ograniczenia przyrządu

Poza10 rastrami etykiet obejrzano `edit-desktop-light.png` i `wyszlo-viewport-dark.png`. Nie obejrzano każdego fragmentu każdego formularza. Pierwsze pełnostronicowe zrzuty przy zoom2 miały przyciętą powierzchnię; nie zaliczono tego jako błędu aplikacji. Końcowe rastry używają CDP `captureBeyondViewport:false`.

Pozostają: trwały log całego przebiegu walidacji/korekty, wysłanie podziękowania z Wyszło oraz odrębne badania na urządzeniach/czytnikach. Pokrycie Tab/radiów i sprawdzenie widocznego fragmentu fokusu pięciu etykiet są zakończone w opisanym zakresie. Nie zmieniano aplikacji i nie potwierdzono nowej regresji.

Nie uruchamiać `run.mjs` bez odczytu: zapisuje tytuł, a jego stare porównanie identycznych UUID zatrzymuje przebieg. Pozostałe helpery służą odczytowi i lokalnej obsłudze klawiatury; GET Wyszło może zmieniać stan przeczytania powiadomienia. Setupu nie powtarzać.

## Dowody w repo

Źródło: 8e4da2f2ff51178df57d7dd118978e441b8c5130. Dane pomiarowe i porównanie pól: [katalog JSON](evidence/edycja-wyszlo492/). PNG i reproduktory pozostają w lokalnym output/edycja-wyszlo492; ich ogląd opisano powyżej. Nie są załączone do repo.
