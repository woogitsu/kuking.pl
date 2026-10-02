# Stara strona „Moich wpisów” — #2473

Po zmniejszeniu listy przez usunięcie wpisu albo wyłączenie pytań numer
strony może wyjść poza aktualny zakres. Kontroler przekierowuje wtedy do
ostatniej istniejącej strony; dla naprawdę pustej listy do pierwszej,
bez pętli. Zakres właściciela i filtr rodzajów pozostają w `MojeWpisy`
(D-328), bez zmiany widoczności ani kolejności.

Kontener `lista-moich-wpisow` istnieje także na pustej liście. Pobranie przez
„Pokaż więcej” może podążyć za przekierowaniem; istniejący moduł pomija znane
`data-klucz`, a brak następnego linku oznacza koniec. Nie usuwa wcześniej
wczytanych kart. Pusty stan dotyczy `total() === 0`, nie pustej dalszej strony.

Regresja HTTP odtwarza 21 → 20 wpisów, daleką stronę, wyłączenie pytań,
rzeczywiście pustą listę oraz pobranie z `Accept: text/html`. Test rzeczywistego
modułu w Chromium odtwarza HTTP 302 do znanych kart i do pustego kontenera:
wcześniejsze karty zostają dokładnie raz, przycisk znika, ogłoszenie mówi o
końcu. Serwer tego testu jest kontrolowaną odpowiedzią; rzeczywisty kontroler
i kształt pustego HTML mierzą osobne testy HTTP. Kontrole ujemne
wyłączają korektę zakresu albo usuwają tożsamość pustego kontenera; wymagają
odpowiednich markerów i zielonego testu po dokładnym przywróceniu źródła.

Bez migracji. Wycofanie kodu przywraca poprzednie zachowanie paginacji,
bez zmiany danych; stare adresy znów mogą pokazywać mylący pusty stan.
