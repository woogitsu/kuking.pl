# Odzyskiwanie edycji i logowanie linkiem — uzupełnienie #492

Dowody lokalne dotyczą dokładnych źródeł `609328b53b6e87d99fbf178f46dbb2157feeeed7`.
Nie zmieniano kodu aplikacji ani danych produkcyjnych. Bazy `kuking_492_put_browser`
i `kuking_492_login_browser` były oddzielne, na `127.0.0.1:55439`, w UTC.
Ten odbiór uzupełnia wcześniejsze raporty; nie zamyka całej macierzy marki.

## Edycja istniejącego przepisu po 419 i 429

Rzeczywiste ponowienia formularza PUT zachowały opis, trzy instrukcje,
prywatność, czasy i przypisanie istniejącego medium do hero, skanu oraz kroku.
Podczas odmowy baza pozostała niezmieniona. Odzyskany formularz zachował
niepuste pola i UUID kroków. Po poprawnym zapisie akcja domenowa odtwarza
UUID zgodnie z dotychczasowym kontraktem; identyfikator sprzed zapisu służy
do zachowania przypisania zdjęcia. Początkowe wymaganie niezmiennego UUID
po zapisie było błędem przyrządu, nie usterką aplikacji.

419 uzyskano przez rzeczywiste middleware po kontrolowanym usunięciu
`Sec-Fetch-Site` z transportu. Nie wytwarzano odpowiedzi błędu, ale nie jest
to dowód naturalnego wygaśnięcia sesji współczesnego Chromium.
429 wywołała seria rzeczywistych żądań z prawidłowym CSRF; ponowienie
nastąpiło po 544 sekundach przy `Retry-After: 543`, bez resetowania limitu.
Osobny odczyt bazy potwierdził oba zapisy: nadal jeden przepis, trzy kroki
i jedno medium. [Wynik danych](evidence/put-login492/put-db-summary.json).

Agent obejrzał 16 zrzutów: błąd/sukces każdego statusu, 390/1440 px,
jasny/ciemny. Brak poziomego przepełnienia; akcja ponowienia dostępna.
Kadry sukcesu 419 są późniejszym GET bez jednorazowego komunikatu;
sukces 429 zawiera rzeczywiste „Przepis zapisany”. Zapis obu potwierdza baza.
Motyw ustawiono przez `data-theme`, więc nie jest to odbiór przełącznika
ani kontrastu przejściowych kolorów. Podpowiedź wyglądu przykrywa część
stopki/treści sukcesu, lecz nie główną akcję w kadrach błędów.
Nie badano nowych uploadów, pełnego Tab, zoomu 200% ani fizycznego telefonu.

## Pełny przepływ logowania linkiem

Trzy lokalne przejścia objęły formularz, przechwycenie wiadomości w lokalnym
SMTP, potwierdzenie GET, logowanie POST z CSRF, autoryzowaną sesję w bazie
i usunięcie tokenu. Zużyty link otwarty jako gość nie pozwalał ponownie
potwierdzić logowania. Czwartą wysyłkę zatrzymał prawidłowy limit; nie
obchodzono go. Żadna wiadomość nie trafiła do zewnętrznego odbiorcy.

Osobny token kontrolowanie wygaszono przez przesunięcie obu dat fixture.
GET pokazał ekran nieaktualnego linku, POST zwrócił przekierowanie, usunął
token i nie utworzył autoryzowanej sesji. Nie było naturalnego odczekania
całego okresu ważności. [Wynik funkcjonalny](evidence/put-login492/login-result.json).

Ostateczny ogląd użył rzeczywistego formularza zmiany motywu aplikacji.
Formularz prośby o link i ekran nieaktualnego linku: 390/1440 px × oba
motywy, 8/8 bez poziomego przepełnienia, wszystkie osiem PNG obejrzane.
Początkowy przyrząd używał preferencji systemowej, która nie przełączała
aplikacji: jego pliki nazwane „dark” nie są dowodem ciemnego motywu.
Nie włączono tych mylących obrazów do dowodów niniejszego raportu.

Potwierdzenie wysyłki, ważny token i sukces mają wcześniejsze dowody jasnego
motywu. Nie sprawdzono wszystkich pośrednich stanów w pełnym iloczynie
konfiguracji ani rzeczywistych klientów poczty i fizycznych urządzeń.

## Pozostałe prace

- Odbiór dodatkowego błędu pliku oraz pełnej klawiatury formularza zdjęć.
- Brakujące konfiguracje pośrednich stanów logowania, jeśli wymaga ich
  końcowa macierz; bez powtarzania już dowiedzionego sukcesu funkcjonalnego.
- Rzeczywiste programy pocztowe i urządzenia pozostają osobnymi ograniczeniami.
- Włączenie tych wyników do końcowej macierzy nie oznacza odbioru produkcji.

Pełne lokalne raporty i obrazy: `output/492-put-receipt/` oraz
`output/492-login-receipt/` w repo kanonicznym. Surowych tokenów, sesji,
payloadów i fixture z poświadczeniami nie wolno dodawać do repo.
