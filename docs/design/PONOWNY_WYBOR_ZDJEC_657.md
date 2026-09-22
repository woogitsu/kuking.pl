# Ponowny wybór nowych zdjęć — #657

Gdy formularz wpisu odrzuca zbyt duży plik przed przechowaniem nowych zdjęć,
cały nowy wybór trzeba wskazać ponownie. Komunikat brzmi:

> Jedno ze zdjęć waży za dużo. Wybierz ponownie wszystkie nowe zdjęcia — każde do 15 MB.

Wartość limitu pochodzi z konfiguracji. „Nowe” odróżnia wybrane pliki od
zdjęć przechowanych przy wcześniejszej walidacji i przekazanych przez
`media_ids`. Te zdjęcia pozostają zachowane.

Tekst jest lokalny dla `PostController::store`: trafia zarówno do
`ObslugiwaneZdjecie`, jak i dodatkowej reguły `max`. Pierwsza reguła sama
sprawdza rozmiar, więc zmiana samego `photos.*.max` nie zmieniłaby pierwszego
widocznego komunikatu. Domyślny tekst reguły i `LimityZdjec` pozostaje
niezmieniony dla przepisu, awatara, wykonania i innych konsumentów.
Wyjątki `StoreUploadedImage` są osobną ścieżką.

Regresja `OdrzuconyWyborZdjecWpisuTest` wykonuje odrzucenie i powrót do
formularza, porównuje tekst osobno w podsumowaniu i przy polu, sprawdza
opis, prywatność, brak nowych zapisów, zachowane medium oraz publikację
po ponownym wyborze poprawnego pliku. Dane obejmują limit 15 MB i 2 MB.

Odbiór lokalny: 12 testów / 70 asercji, Pint trzech plików i PHPStan zakresu
bez błędów. Fizyczny powrót domyślnego tekstu w regule spowodował dwie
oczekiwane porażki, po przywróceniu 12/70 PASS. Przeglądarka: 390 i 1440 px,
rzeczywisty jasny/ciemny motyw, cztery błędy rzeczywistego PNG powyżej limitu
i cztery poprawne zapisy po korekcie; obejrzano osiem zrzutów.

To lokalne przygotowanie poprawki. Pełny hook, CI i wdrożenie pozostają
odrębnymi etapami. Szczegółowy raport roboczy: `output/657/ODBIOR.md`.
