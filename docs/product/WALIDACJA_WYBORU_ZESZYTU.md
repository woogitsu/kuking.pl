# Wybór zeszytu przy zapisywaniu — issue #473

Oba endpointy zapisywania (przepis i wpis) sprawdzają format `collection_id`
przed zapytaniem do PostgreSQL. `bail` kończy sprawdzanie po błędzie UUID.
Istnienie zeszytu jest sprawdzane wyłącznie w obrębie konta wysyłającego
żądanie. Cudzy i nieistniejący zeszyt dają to samo zdanie, bez nazwy
zeszytu ani informacji o jego właścicielu:

> Odśwież stronę i ponownie wybierz zeszyt do zapisania.

Komunikat jest renderowany w obudowie ekranu także po powrocie na strumień,
który nie ma własnego podsumowania walidacji. Test sprawdza jego treść
w konkretnym elemencie po przekierowaniu.

Brak pola lub pusta wartość nadal oznaczają zeszyt domyślny. Autoryzacja
oglądania zapisywanej treści pozostaje przed walidacją. Walidacja nie
zastępuje ograniczenia zapytania pobierającego zeszyt do jego właściciela.

## Sprawdzenie

`tests/Feature/WyborZeszytuMaWalidacjeTest.php` wykonuje żądania HTTP do
obu endpointów: tekst zamiast UUID, tablica, nieistniejący UUID, cudzy
zeszyt, własny zeszyt oraz brak wyboru (pominięte pole i pusty ciąg).
Odmowy sprawdzają dokładny komunikat, brak zapisu oraz brak nowo
utworzonego zeszytu domyślnego. Kontrole dodatnie sprawdzają docelowy
wiersz `collection_items`, a nie tylko przekierowanie.

W środowisku przygotowania poprawki nie było PHP, Composera ani
PostgreSQL. Testy i kontrole ujemne poniżej wymagają wykonania na pełnym
checkoutcie z bazą testową; nie są raportem przeprowadzonego pomiaru.

1. Uruchom `php artisan test --filter=WyborZeszytuMaWalidacjeTest`.
2. Zachowaj kopię zmienionego kontrolera poza repozytorium. W kopii roboczej
   przywróć stare pobieranie zeszytu w obu metodach i uruchom ten sam test:
   przypadki niepoprawnych identyfikatorów muszą oblać się wskutek braku
   odpowiedzi walidacyjnej. Przywróć kontroler z zachowanej kopii.
3. Osobno usuń warunek `owner_id` z reguły `exists`, zachowując ograniczenie
   pobrania zeszytu. Przypadki `foreign` muszą oblać się: zamiast walidacji
   będzie 404. Przywróć kopię i ponownie sprawdź zielony przebieg.
4. Po każdym sabotażu i przywróceniu porównaj md5 kontrolera z kopią
   poza repozytorium; przywracaj przez `cp`, nigdy przez `git checkout`
   ani `git stash`.
5. Uruchom istniejące testy zeszytów, formatowanie i pełne CI. Sprawdź
   ewentualne starsze asercje oczekujące 404 dla cudzego `collection_id`:
   wymaganym wynikiem jest teraz ten sam błąd walidacji co dla brakującego.

Poprawka nie zmienia schematu ani danych. Wycofanie polega na cofnięciu
zmiany kodu; przywraca jednak możliwość błędu 500 dla błędnego identyfikatora.
