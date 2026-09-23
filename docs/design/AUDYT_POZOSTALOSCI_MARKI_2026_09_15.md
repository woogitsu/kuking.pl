# Co zostało z portu marki — 15 września 2026

Status pełnego portu: **CZĘŚCIOWO**. Podstawa kodu: `ac5ff9d7716d000318870a2120522fdd7930303a` (PR#580); produkcja odczytana w zalogowanym Chrome: Alfa0.38/61360bf. PR#580 dodaje funkcję kolejki, nie nową oprawę panelu. Nie należy nim zamykać całego portu moderacji.

## Metoda i granice

Inwentaryzacja156 plikówBlade, rodzin tras, wspólnych komponentów i arkuszy; porównanie aktualnej konstytucji1.14, źródłowegoZIP i historycznej macierzy z późniejszymi odbiorami. Root: główne ekrany i wspólna rama; dwaj niezależni recenzenci: admin oraz auth/settings/static/errors/offline/export/mail. Trzeci odczyt issues uzgodnił zakończone i nadal otwarte pakiety.

Produkcja: zrzut właściciela Zgłoszeń oraz rzeczywisty odczyt zalogowanej kolejki. Ten audyt nie jest nowym oglądem156 widoków ani wszystkich kombinacji. Historyczne pomiary pozostają dowodami tylko ich zakresu. Obecność klasy marka-* lub nowej palety nie stanowi pełnego odbioru kompozycji.

## Potwierdzony brak: panel moderacji (#581)

Nowa rama jawnie wyklucza panel przez `:not([data-tryb-panelu])` w resources/css/marka-rama.css:39–58. Pozostają stara siatka z app.css, złota boczna nawigacja i belka panel-moderacji.blade.php. Nowe logo, font i część kart już docierają do panelu, lecz kompozycja nie została przeportowana.

Złoto było historycznym sposobem rozpoznania panelu. Należy zachować rozpoznawalny tryb, osobną nawigację, nazwę i wyjście, uprawnienia oraz2FA. Najnowsze zgłoszenie wymaga nowej oprawy; nie wymaga zachowania konkretnego złotego koloru. ZIP nie zawiera kompletnego panelu admin — potrzebna jest adaptacja zasad marki.

Zakres do wykonania:

| Obszar | Widoki |
|---|---|
| Wspólna rama | układ, nawigacja boczna/mobilna, nagłówek trybu, aktywna pozycja, liczniki |
| Decyzje | zgłoszenia, sygnały automatu, odwołania |
| Odzew | wpisy, przepisy i Ugotowałem w Bez odpowiedzi |
| Kontakt | lista wiadomości i szczegół z formularzem odpowiedzi |
| Redakcja | tablica na dziś, kolaż powitalny, tagi promowane |
| Konta | lista użytkowników i szczegół użytkownika |
| Dostęp | bramka wymagająca2FA |

To11trasGET oraz warianty kolejki i bramka. Każda rodzina wymaga pełnego/pustego stanu, rozwiniętych akcji, walidacji i wyników operacji. D-089 brak pustej szyny, D-129 jedno wejście poza panelem, D-138 pełna szerokość, D-144 odbiór rzeczywistych pełnych danych pozostają wymaganiami. Istniejące testy decyzji i 72konfiguracje#579 nie zastępują tego portu.

## Pozostałe rodziny: kod już przeportowany, odbiór niepełny

| Rodzina | Co jest w kodzie | Co konkretnie zostało |
|---|---|---|
| Landing i Start | nowa rama, ciemny kafel, kompozycja01–03, ciemny blok Ugotowałem, duże zdjęcia tablicy, zwarte kolumny | pełne kombinacje długich/pustych treści; nie powtarzać ukończonych#557/#560/#574 |
| Wyszukiwanie i odkrywanie | nowe pole, zakresy i kafle promowanych tagów, wspólne karty i szyny | #568: nawigacja po ponad200 wynikach; kombinacje filtrów i pełna droga klawiaturą; to również rzeczywisty błąd funkcji, nie sam wygląd |
| Wpisy, zdjęcia, komentarze, wykonania | wspólne nowe karty, menu i formularze | #561: fokus wysokiego zdjęcia; pozostałe orientacje/tryby/błędne pliki, karuzele i pełne otwarte menu przy dużym tekście |
| Przepisy, publikowanie i gotowanie | nowy nagłówek tekst+zdjęcie, akcje, wspólne formularze i kreator | pozostałe warianty walidacji/odzyskiwania z mediami; fizyczny telefon, WakeLock/praca w tle; nie mylić błędów#569/#571 z naprawioną odmianą#548 |
| Profile i relacje | ciemny profil, duży awatar, statystyki i zdjęcia w szynie; listy relacji używają wspólnych kart | pełne/puste listy obserwujących i obserwowanych, paginacja, długie nazwy i różne uprawnienia — pełny odbiór brakującej ścieżki |
| Zeszyty | ciemne foldery, karty przepisów i informacje o niedostępnych zapisach | end-to-end: utworzyć→zapisać→odejść→odnaleźć→otworzyć; klawiatura, oba motywy i zoom. #567 już zakończone |
| Onboarding i powiadomienia | duże zainteresowania, zwykłe powiadomienia z akcją obok treści | brak propozycji, wszystkie typy decyzji, błędy i działania zbiorcze z rzeczywistym wynikiem |
| Logowanie, tokeny, OAuth,2FA | marka-wejscie i wspólna rama; port pięciu stanówOAuth istnieje | ważne/zużyte/wygasłe tokeny, zaproszenia, reset, błędne kody; rzeczywisty dostawca i końcowe operacjeOAuth wymagają osobnego odbioru |
| Ustawienia | nowe wspólne formularze, port szybkiego wyglądu | szczególne stany e-maila, prywatności, bezpieczeństwa/2FA, eksportu; zapis wyglądu nie dowodzi pozostałych preferencji |
| Pomoc i informacje | nowa wspólna rama, aktualne tokeny | każda długa strona przy prawdziwym zoomie i pełna klawiatura; ocena prawna nie była częścią tego audytu |
| Kontakt, zgłoszenia użytkownika, odwołania | nowe wspólne komponenty; istnieją wybrane rzeczywiste odbiory | nieodebrane puste/pełne listy, sukcesy i wszystkie rozgałęzienia formularzy |
| Błędy500/503 i offline | obecna paleta, samodzielne układy, ciemny wariant | aktualnyzoom i instalacja/aktualizacjaPWA na docelowych systemach.419/429 mają późniejsze rzeczywiste dowody — dawny wpis o samymHTTP200 jest historyczny |
| Eksport i druk | obecna paleta, HTML/ZIP i jasnydruk mają odbiór | inne przeglądarki, pełnyzoom i offline; systemowyfont jest celowym wyjątkiem |
| Poczta |11własnych i7LaravelMailMessage mają port oraz36 obejrzanych renderówChromium | prawdziwyGmail/Outlook/AppleMail, ciemnytryb klienta i odmienne treści. Nie zgłaszać ponownie „brak portuLaravel” |

## Dokumentacja też wymaga uporządkowania

Macierz517 łączy kolejne aktualizacje z dawnymi kolejkami. Ma aktualne uzupełnienia, ale dalej zawiera historyczne „CI/wdrożenie oczekuje” dla już zakończonych pakietów. Trzeba zbudować jeden bieżący widok statusów z linkami do historycznych dowodów, bez usuwania granic pomiarów. Podobnie komentarze o dawnym UIkit nie są automatycznie bieżącymi wytycznymi. Przykład: komentarz search.blade.php opisuje dawne zakazane Odkrywaj, podczas gdy późniejszeD-207 ustala takie menu desktopowe. Kod interfejsu należy oceniać według późniejszej decyzji, komentarz skorygować przy porządkowaniu instrukcji.

## Kolejność

1. Dokończyć funkcjonalne#579/PR580 poCI, bez deklarowania zakończenia portu panelu.
2. #581: wspólna oprawa panelu i wszystkie wymienione rodziny; to najwyższy potwierdzony brak wizualny zgłoszony teraz przez właściciela.
3. #568 i#561 — odtworzone problemy, potem pełna droga zeszytu i tokeny/2FA.
4. Pozostałe stany macierzy, samodzielne powierzchnie i programy pocztowe w dostępnych środowiskach.

Operacyjne kopie/R2/EmailLabs nadal pozostają osobnymi bramkami. Ulepszenia i scenariusze#576 nie zastępują prawdziwych badań#15. Nie znaleziono w sprawdzonym triażu otwartego issue z dostatecznym dowodem do zamknięcia; nie zamykać#492 ani#581 na podstawie zielonegoCI lub samego odczytuCSS.
