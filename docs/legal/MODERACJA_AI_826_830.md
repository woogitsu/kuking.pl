# Moderacja AI — odbiór #827, #828, #826, #829, #830

Stan bazowy: `534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`, gałąź
`gpt/moderacja-ai`. Praca i pomiary: 20 września 2026.

## Zmiany

- **#827:** `AutomaticAnalysisAccess` czyta bieżący stan komentarza i jego
  rodzica. Opiera dostęp na istniejących Policy bez uprawnień właściciela lub
  moderatora. Widoczność `followers` pozostaje dopuszczona zgodnie z D-055:
  wyłącznie na niezapisywanej kopii przekazanej do Policy traktujemy odbiorców
  jak publicznych. Prywatnego rodzica odrzucamy przed takim sprawdzeniem.
  Wykonanie dziedziczy warunki przepisu i własnego `CookedEventPolicy`.
- **#828:** trzy wskazane miejsca zapisują klasę wyjątku, nigdy jego wiadomość.
  Etap wynika ze stałego komunikatu; błąd HTTP nadal zapisuje tylko status.
- **#826:** pusty słownik, same nieznane kategorie, błędne typy, wartości
  nieskończone i wartości poza 0–1 dają `null`. Każdy wadliwy element unieważnia
  odpowiedź. Co najmniej jedna znana kategoria musi mieć poprawny wynik;
  dodatkowe nowe, poprawne kategorie pozostają obsługiwane opisem ogólnym.
  Nie wymagamy pełnej listy kategorii, nie zmieniamy progów ani pola `flagged`.
- **#829:** lokalny sygnał zapisuje się przed HTTP. Tekst i każde wybrane zdjęcie
  są oceniane osobnymi zadaniami: najwyżej jedno HTTP na zadanie, timeout HTTP
  1–8 s (także przy konfiguracji 0, ujemnej lub 60), nawiązanie połączenia 3 s,
  zadanie 30 s. Pobranie/przekodowanie zdjęcia odbywa się tylko w jego zadaniu;
  jego twardy timeout nie zabierze lokalnych ani wcześniejszych wyników.
- **#830:** publikacja zleca ocenę wybranych gotowych zdjęć, a zakończenie
  `ProcessUploadedImage` zleca ocenę tego konkretnego zdjęcia po uzyskaniu
  `ready`. Zadanie ponownie sprawdza przypięcie, wybór w limicie, status i
  dostępność wpisu. Limit pozostaje domyślnie 2, technicznie 0–6. Nie ma pętli
  oczekiwania na odrzucone/usunięte zdjęcie ani wysyłki oryginału.

## Decyzja właściciela i łączenie wyników

W tej sesji właściciel zatwierdził: **„Tak — uzupełniaj otwarte zgłoszenie,
zamknięte zostaw”**. Uzupełnienie D-055 zapisano w `docs/DECISIONS.md`.

`OznaczDoPrzegladu` ma jawny tryb uzupełnienia. Pod blokadą wiersza dopisuje
unikalne powody i podnosi kwalifikację do cięższego sygnału; `resolved` oraz
`rejected` pozostawia bez zmian. Istniejący indeks jednego oznaczenia zostaje.
Dotychczasowi wywołujący bez tego trybu zachowują dotychczasową deduplikację.
Opis przypomina moderatorowi, że zebrane sygnały nie dowodzą pełnej oceny.
Nowe uzupełnienie ma wpis audytowy `content.automat_supplemented`.

## Własne pomiary

Środowisko: WSL Ubuntu, runtime `/home/mateusz/flota/gpt-moderacja-ai-run`,
własna baza `kuking_flota_gpt-moderacja-ai`, użytkownik `kuking`, wyłącznie
`127.0.0.1:55439`. Nazwa argumentu wspólnego skryptu musi być
`gpt-moderacja-ai`, ponieważ skrypt wybiera worktree bezpośrednio po nazwie;
podany pierwotnie katalog `moderacja-ai` nie istnieje.

- Nietknięty kod: 27 istniejących testów moderacji, 79 asercji — zielone.
- #827 przed poprawką: 15 przypadków prywatnego/ukrytego/usuniętego rodzica
  lub niedostępnego autora wysłało żądanie. Trzy dodatkowe porażki pierwszej
  próby wynikały ze złej fixture `erased` bez `data_erased_at`, nie z produktu.
  Po poprawieniu fixture i kodu: 27 przypadków, 120 asercji — zielone.
  Publikację komentarza wykonano akcją domenową, zmianę prywatności wpisu
  przez `EditPost`; mutacje przepisu wykonano bezpośrednio przez model.
- #828: każda z trzech ścieżek rzeczywiście przekazała syntetyczny znacznik
  tekstu, adresu i sekretu do atrapy loggera. Po poprawce wszystkie trzy
  zachowują klasę wyjątku i odrzucają znaczniki. Pierwsza próba obrazu miała
  błędną fixture `variants`; poprawiono ją przed pomiarem tej ścieżki.
- #826: publiczne wejścia tekstu i obrazu przez `Http::fake`; 20 błędnych
  zestawów przed poprawką nie zwracało `null`. Dodatkowe dwie początkowe
  porażki były złą asercją dotyczącą nowej kategorii: istniejący kontrakt
  dopuszcza jej sygnał z opisem ogólnym; zachowano go.
- #829: test przed poprawką potwierdził brak lokalnego zgłoszenia w chwili
  rozpoczęcia HTTP. Po poprawce zgłoszenie jest już zapisane i późniejszy
  wynik modelu go uzupełnia.
- #830: publikacja ze zdjęciem `processing`, analiza tekstu, rzeczywiste
  przekodowanie obrazu — przed poprawką brak dalszego zadania, po poprawce
  wysłano JPEG miniatury i uzupełniono istniejące zgłoszenie. Powtórzenie
  wykonania nie dubluje powodu ani wiersza.
- Wolne odpowiedzi: rzeczywiste oczekiwanie atrapy po 5 s na każde HTTP.
  Tekst + 2 zdjęcia: **15,06 s** łącznie; tekst + 6 zdjęć: **35,09 s**.
  Każde osobne zadanie zmieściło się w 15 s, zapis lokalny istniał przed
  każdą odpowiedzią. To pomiar aplikacji z atrapą opóźnienia, nie pomiar
  produkcji ani zabicia workera sygnałem systemowym.

Nie korzystano z cudzych pomiarów jako dowodu poprawności. Opisy issues
stanowiły wskazówki do własnej reprodukcji.

## Końcowe sprawdzenia

- Pełny przebieg: **4469 testów, 83817 asercji, 370,94 s**. Pominięto
  `ProbaOdtworzeniaTest` (jawnie dozwolony wyjątek: współdzielona baza próby)
  i grupę `wolny-model`, wykonaną oddzielnie: **2 testy, 32 asercje**.
- Następnie dodano przypadek odwrotnej kolejności: zdjęcie przed tekstem.
  Test najpierw oblał, bo lokalny powód nie uzupełniał wcześniejszego wyniku
  zdjęcia. Po włączeniu trybu uzupełnienia także dla lokalnego sygnału:
  **115 testów moderacji, 380 asercji** oraz **21 testów SygnalyAutomatuTest,
  60 asercji** — zielone. Pełnego przebiegu po tej ostatniej zmianie nie
  powtarzano; wykonano powyższe sprawdzenia obszaru zmiany.
- PHPStan całego projektu: bez błędów. Pint: 14 plików bez uwag.
  `git diff --check`: bez uwag. Nie budowano assetów — brak zmian frontendu.
- Siedem fizycznych mutacji: granica rodzica, każde z trzech miejsc logowania,
  walidacja wyniku, zapis przed HTTP, wywołanie po gotowości obrazu.
  Każda dała **PASS → FAIL z oczekiwanego powodu → PASS**. Skrypt potwierdził
  przywrócenie MD5 i czasu modyfikacji. Pełny zapis:
  [kontrole ujemne](MODERACJA_AI_826_830_KONTROLE.txt).
  JSON narzędzia ma pole przywrócenia zapisane przed jego końcowym mechanizmem
  sprzątania; dowodem odtworzenia jest końcowy komunikat porównania w tym zapisie.
- Zachowany limit opisu zgłoszenia wynosi 2000 znaków; bardzo długie zbiory
  powodów mogą być ucięte. Nie zmieniano schematu ani tego dotychczasowego limitu.

Zatwierdzona decyzja została wdrożona. W zakresie tych pięciu poprawek nie
pozostała decyzja właściciela blokująca odbiór; osobne znaleziska poniżej
wymagają osobnego zakresu pracy.

## Ograniczenia i wycofanie

- Nie wykonano żądań do prawdziwego OpenAI ani wysyłek do ludzi. HTTP, poczta,
  kolejka i storage w testach są kontrolowane lokalnie.
- Sprawdzenie dostępu obejmuje stan przy wykonaniu i przed zapisem wyniku.
  Nie jest atomowe z zewnętrznym HTTP: zmiana prywatności po sprawdzeniu może
  wyprzedzić wysyłkę. Nie trzymamy transakcji SQL przez żądanie zewnętrzne.
- Jednoczesne oczekujące zadania zdjęcia są ograniczone `ShouldBeUnique`.
  Ponowne dostarczenie po ukończeniu może ponowić HTTP; idempotentny jest
  zapis oznaczenia, nie obietnica dokładnie jednego żądania do dostawcy.
- Brak migracji. Wycofanie: wyłączyć model pustym `OPENAI_MODERATION_KEY`,
  opróżnić lub zakończyć zadania `PrzeanalizujZdjecieWpisu` przed cofnięciem
  kodu zawierającego tę klasę, następnie odwrócić lokalne commity pakietu.
  Istniejące zgłoszenia i dopisane powody pozostają; nie kasować ich.
- Nie wykonano push, PR ani CI — zgodnie z zasadami floty.

## Osobne znaleziska z odczytu — poza poprawką

1. `PrzeanalizujAwatar::handle` także loguje `getMessage()` w zewnętrznym
   `catch`. Nie objęto go automatycznie zakresem #828, który wymienia trzy
   inne miejsca. Wymaga osobnej reprodukcji i zgłoszenia.
2. `OcenaModelem::jakoJpeg` używa `wariantDoSerwowania('thumb')`. Ta metoda
   może zastąpić brakującą miniaturę innym wariantem, włącznie z dużym;
   przekodowanie do JPEG nie zmniejsza wymiarów. Wymaga osobnego sprawdzenia
   granicy „pomniejszone zdjęcie” na historycznych/uszkodzonych metadanych.
3. Aktualna polityka i kod obejmują także komentarze oraz zdjęcia profilowe
   (D-055/D-063), szerzej niż skrót „treść wpisu i pomniejszone zdjęcie”
   w poleceniu. Nie rozszerzono tego zakresu w tej pracy. Sama treść może
   zawierać dane identyfikujące wpisane przez człowieka; kod nie usuwa ich
   semantycznie. Odczyt nie dowodzi incydentu produkcyjnego.
