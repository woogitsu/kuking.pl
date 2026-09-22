# Ograniczony lokalny odbiór kolejek moderacji i odwołań

Źródło skopiowane z commita `15dd0dbcd2777dc3a362929b5c79278eedafcc1d`. Osobna kopia /tmp/kuking-moderacja-537, baza kuking_audit_moderacja537 na PostgreSQL na porcie 55439 i osobne storage. Brak zmian aplikacji i produkcji. Raport nie oznacza pełnego audytu moderacji.

## Izolacja

Przed utworzeniem danych helper potwierdzał `APP_ENV=local`, dokładną nazwę aktywnego połączenia i `MAIL_MAILER=array`. Serwer działał z `QUEUE_CONNECTION=sync`; nie uruchamiano workerów ani harmonogramu. Sprawdzone akcje NotifyModerationDecision/NotifyReporterDecision zapisują powiadomienia w lokalnej bazie; pocztowy kanał DecyzjaWSprawieZgloszenia używa mail, skierowanego tutaj do array. W użytych ścieżkach nie znaleziono zewnętrznego webhooka. Konteksty głównego odbioru blokowały żądania przeglądarki poza 127.0.0.1. Nie testowano dostarczenia prawdziwego e-maila.

Cztery konta i dwa wpisy/zgłoszenia utworzono wyłącznie w tej bazie. Role autor, zgłaszający, moderator i administrator. Moderator i administrator mieli potwierdzone 2FA jako stan fixture; logowanie rzeczywiście przeszło hasło i wyzwanie TOTP. Nie wyłączono żadnego middleware ani policy. Nie badano procesu pierwszego włączania 2FA. Sekrety, kody i pliki sesji pozostają poza repo i raportem.

## Faktycznie wykonane działania

1. Moderator otworzył dwa zgłoszenia z długimi wieloakapitowymi opisami i długim adresem example.test. Prawdziwymi formularzami z CSRF ukrył jeden wpis oraz wybrał brak działania dla drugiego. Kontrola bazy potwierdziła resolved/rejected, wpis moderacyjny i powiadomienia.
2. Zgłaszający otworzył własną kartę; jej tekst nie zawierał unikalnego znacznika notatki wewnętrznej. Autor próbujący tej cudzej karty otrzymał 403.
3. Autor złożył przez formularz długie odwołanie od ukrycia. Moderator mógł zobaczyć kolejkę, ale nie miał formularza rozstrzygnięcia; dodatkowy rzeczywisty POST do rozstrzygnięcia jako moderator zwrócił 403.
4. Administrator otworzył kolejkę, w której zachowana była pełna wiadomość pierwotnej decyzji, następnie formularzem cofnął decyzję z długim uzasadnieniem. Baza potwierdziła overturned i powrót wpisu do published. Autor widział wynik, a administrator historię w filtrze rozstrzygniętych. Otwarte kolejki zgłoszeń i odwołań pokazały puste stany.

Nie wykonywano zawieszania ani usuwania kont, drogi prawnej, odwołania podpisanym linkiem zgłaszającego, odwołania osoby zablokowanej, przywracania mediów ani ponownego rozstrzygnięcia sprawy. Nie badano pełnej matrycy walidacji i limitów czasowych.

## Geometria i zrzuty

Wykonano pomiary przy 320/768/1440, obu motywach, tekście 100/140: kolejka zgłoszeń przed decyzją, formularz odwołania, kolejka odwołań, wynik autora oraz oba puste stany. Sprawdzano scrollWidth względem viewportu oraz elementy tekstowe z overflowXhidden/clip. W tych pomiarach nie stwierdzono poziomego przepełnienia.

Końcowy zachowany geometry.json zawiera 48 pomiarów geometrii (kolejka odwołań, wynik i dwa puste stany) oraz 8 pomiarów zoom i zapis sprawdzenia wyniku. Wcześniejsze pomiary kolejki zgłoszeń i formularza odwołania wykonano, a zrzuty zachowano, ale ich pełna tablica JSON nie została zachowana po zatrzymaniu pomocniczego skryptu na porównaniu tekstu z końcową spacją. Nie przypisujemy jej kompletnego końcowego logu. Porównanie poprawiono względem rzeczywistego tekstu zapisanego przez TrimStrings; nie zmieniono aplikacji.

Prawdziwe powiększenie 200% ustawiano przez chrome.tabs.setZoom i sprawdzano getZoom=2, fizyczne 640×1000 dawało CSS 320×500. Obejmowało cztery ekrany (zgłoszenia, formularz odwołania, kolejkę odwołań, wynik), oba motywy i tekst 100/140: 16 wykonanych wariantów. Osiem końcowych ma zachowany JSON, wcześniejsze osiem zrzuty. Nie jest to emulacja rozmiaru fontu. Chromium 153.0.8010.12.

Obejrzano mobilną kolejkę odwołań w ciemnym motywie 140, wynik autora przy zoomie 200/tekst 140 oraz przewinięty fragment zamkniętego odwołania. Długa treść zawija się i wymaga znacznego przewijania. Przyzoom200/tekst 140 stałe nawigacje zajmują dużą część wysokości, co jest ograniczeniem widoku; ten odbiór nie ustalił nowej całkowicie niedostępnej kontrolki.

## Tab — osobny, wąski zakres

Na zamkniętych kolejkach wykonano po 65 naciśnięć Tab przy 320/tekst 140. Zachowane tab.json opisuje 16 trafień w linki głównej treści oraz kod 403 próby rozstrzygnięcia. Trafienia miały focus-visible i solid 3 px. To NIE jest kompletne sprawdzenie Tab pól otwartych formularzy ani ich zasłaniania przy prawdziwym zoomie. Nie przedstawiamy tego jako pełnego odbioru fokusu moderacji.

## Wynik

W wykonanych działaniach nie potwierdzono usterki aplikacji ani naruszenia opisanych uprawnień. Nie zmieniono kodu i nie obniżano progów. Dowody w output/moderacja537: zrzuty, geometry.json, tab.json i syntetyczny state-after.json. Dane testowe pozostały wyłącznie w izolowanej bazie; serwer po odbiorze zatrzymano. Na tym etapie pozostawał Tab otwartych formularzy. Późniejsze, ograniczone uzupełnienie jest opisane poniżej.
Niezależny review porównał raport z zachowanymi JSON i 16 zrzutami zoomu;
nie znalazł twierdzeń wykraczających poza opisany zakres. Agent prowadzący
obejrzał dodatkowo desktopową kolejkę, mobilny fragment zamkniętego
odwołania i wynik autora przy powiększeniu 200% oraz tekście 140%.


## Uzupełnienie odbioru: Tab otwartych formularzy i błędów

Osobna kopia /tmp/kuking-moderacja-537 i baza kuking_audit_moderacja537, `MAIL_MAILER=array`, pełne middleware oraz rzeczywiste logowanie moderatora przez hasło i TOTP. Nowe oznaczone fixture: otwarte zgłoszenie oraz ukryty testowy wpis z decyzją należącą do autora. Nie zmieniono wcześniejszych wyników rozstrzygnięcia ani aplikacji. Formularze walidacyjne wysłano wyłącznie lokalnie z CSRF i niepoprawnymi danymi; nie zapisano nowych decyzji ani odwołania.

### Dokładny zakres

8 wariantów: otwarty formularz zgłoszenia moderatora i formularz odwołania autora × stan normalny/błąd walidacji × motyw jasny/ciemny. Wszystkie przy rzeczywistym zoomie 200% (chrome.tabs.setZoom/getZoom=2), fizyczne 640×1000 → CSS 320×500, tekst aplikacji 140%. Chromium 153.0.8010.12.

Moderator: grupy radiowe decyzji i zawieszenia, pole własnej liczby dni, podstawa decyzji, notatka wewnętrzna, wiadomość autora i zapis. Autor: wyjaśnienie, wysłanie i istniejący link formularza; przy błędzie również link w podsumowaniu. Radio policzono zgodnie z natywną kolejnością Tab jako grupę, nie jako osobny przystanek każdej opcji. Nie badano przełączania wszystkich opcji strzałkami ani wszystkich zależnych kombinacji zawieszenia.

Wymuszona walidacja moderatora: brak decyzji i podstawy. Walidacja autora: jednoznakowe wyjaśnienie krótsze od minimum. Sprawdzono obecność jednego podsumowania błędów; miało ono rzeczywisty fokus po GET w obu motywach.

### Wyniki

Kompletność 42/42 oczekiwanych przystanków Tab, 46 zapisanych odwiedzin (część odwiedzona ponownie). Brak brakujących pól/kontrolek. Dla każdego odwiedzonego elementu zapisano focus-visible, outline, box-shadow, obszar w viewport i rzeczywisty hit-test elementFromPoint. Każda kontrolka miała widoczny fragment, nie wykryto całkowitego zasłonięcia. Po Tab odczekano 300 ms na zakończenie przejścia pierścienia, zamiast mylić jego stan przejściowy z brakiem fokusu.

Przycisk primary korzysta z box-shadow, dlatego samo outline:none nie jest błędem. Obejrzany zrzut pokazuje wyraźny pierścień przy „Zapisz decyzję”. Długi wielowierszowy link błędu autora pozostaje częściowo przykryty stałymi nawigacjami; widoczny jest rzeczywisty fragment ramki wokół jego tekstu. Nie uznano zbiorczego prostokąta wysokiego tekstu za dowód całkowitego ukrycia. To ograniczenie czytelności przy bardzo małej dostępnej wysokości, a nie deklaracja pełnej zgodności WCAG. Nie zmieniano fokusu, wysokości nawigacji ani stylów.

Podsumowanie błędów moderatora miało prostokąt y111,883–508,414 CSS; autora y110,844–468,313 CSS. Nie mieści się ono w całości między stałymi nawigacjami. Odbiór potwierdza dotarcie klawiaturą i widoczny fragment, nie jednoczesną widoczność całego komunikatu ani pełny audyt wszystkich kryteriów dostępności.

### Dowody

Pełny końcowy JSON: output/moderacja537/tab-open/wyniki.json. Log 8 wariantów: tab-open537-final.log w tym samym katalogu.

Dwa reprezentatywne zrzuty do niezależnego oglądu:

- moderator-true-true-fokus-6.png — ciemny motyw, stan błędu, widoczny pierścień przycisku „Zapisz decyzję”.
- autor-false-true-fokus-3.png — jasny motyw, rzeczywisty fokus na częściowo zasłoniętym wielowierszowym linku błędu.

Nie stwierdzono w tym zakresie usterki wymagającej edycji aplikacji. Po pomiarze serwer i przeglądarki zatrzymano. Dane dostępowe i pliki sesji nie zostały dołączone do raportu ani repo.
Agent prowadzący niezależnie obejrzał oba wskazane zrzuty.

Niezależny review uzupełnienia porównał osiem wariantów JSON i oba zrzuty.
Stałe 300 ms nie czeka na oczekiwany styl ani nie zmienia przewinięcia;
nie maskuje trwałego ukrycia. Zakres i ograniczenia raportu zaakceptowano.
