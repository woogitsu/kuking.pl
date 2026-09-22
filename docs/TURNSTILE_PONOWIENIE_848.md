# #848 — instrukcja po odrzuceniu Turnstile

Stanowisko: `gpt/turnstile-pomoc`, baza kodu `534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`.
Pomiary własne z 20 września 2026. Runtime WSL: `/home/mateusz/flota/gpt-turnstile-pomoc-run`;
PostgreSQL: `127.0.0.1:55439`, baza `kuking_flota_gpt-turnstile-pomoc`, właściciel połączenia `kuking`.

## Wynik przeglądu siedmiu miejsc

| Miejsce | Wynik przed poprawką | Zmiana |
|---|---|---|
| Rejestracja | Hasło puste, pozostałe pola i zgody zachowane; instrukcja pomijała hasło | Przed ponowieniem nakazuje wpisać hasło |
| Logowanie | Hasło puste, login zachowany; instrukcja pomijała hasło | Ta sama korekta |
| Cofnięcie usunięcia konta | Hasło puste, login zachowany; instrukcja pomijała hasło | Ta sama korekta |
| Odzyskanie hasła | E-mail zachowany, instrukcja ponowienia i kontakt widoczne | Bez zmiany tekstu |
| Logowanie linkiem | E-mail zachowany, instrukcja ponowienia i kontakt widoczne | Bez zmiany tekstu |
| Kontakt | Wiadomość, rodzaj i e-mail zachowane, instrukcja poprawna | Bez zmiany tekstu |
| Zgłoszenie nielegalnej treści | Pola, wybór powodu i oświadczenie zachowane, instrukcja poprawna | Bez zmiany tekstu |

Nowy tekst na trzech formularzach z hasłem: „Pozostałe dane nie zniknęły.
Dla bezpieczeństwa wpisz hasło ponownie, a potem wyślij…”. Wspólna reguła
przekazuje miejsce do obu komunikatów; odmowa API, brak tokenu oraz lokalne
odrzucenie nieprawidłowego typu lub długości korzystają z tej samej instrukcji.

Dodatkowo odtworzono przypadek z komentarza do #848: zaproszenie traci ważność
między odczytem w kontrolerze a zużyciem w akcji. Komunikat każe sprawdzić
odtworzony adres e-mail, wpisać hasło ponownie i wysłać formularz. Test używa
prawdziwej rejestracji oraz bazy; kontrolowana odpowiedź Turnstile wygasza
zaproszenie przed zużyciem. To kontrolowany przeplot jednego żądania,
nie pomiar dwóch równoległych połączeń.

## Dowody własne

- Nietknięty kod: `TurnstileWymagaPotwierdzeniaTest` — 28 testów, 244 asercje, zielone.
- Nowy pomiar: POST → przekierowanie → GET, prawdziwe pola i błędy w DOM.
  Cztery odmiany odrzucenia na każdym z siedmiu formularzy; kontrola kompletności
  porównuje siedem miejsc z konfiguracją. Hasło nie trafia do old input ani HTML.
- Kontrola ujemna po dopracowaniu fixture: przywrócenie wspólnej instrukcji
  dało 12 czerwonych przypadków (trzy formularze × cztery odmiany), każdy
  na brakującej frazie „wpisz hasło ponownie”; 17 pozostałych testów przeszło.
  Mutacja potwierdzona wyszukaniem zmienionego tekstu, plik przywrócony w `finally`,
  zgodność MD5 i czasu modyfikacji sprawdzona.
- Wygaśnięcie zaproszenia: test przed zmianą kontrolera czerwony wyłącznie
  na brakującej instrukcji o haśle (23 asercje).
- Zestaw Turnstile po poprawce: 90 testów, 1359 asercji, zielone.
- Uruchomiono `vendor/bin/pint` na całym runtime; poprawki formatowania
  własnych plików przeniesiono do worktree.

- Pełny domyślny zestaw testów z wyłączeniem `ProbaOdtworzeniaTest`:
  **4413 zaliczonych, 84 172 asercje, 343,28 s**, kod wyjścia 0.
  Wyjątek pominięto zgodnie z instrukcją zadania: używa wspólnej bazy próby
  odtworzenia. Nie wykonywano dodatkowej grupy `dwa-polaczenia`, wyłączonej
  domyślnie w konfiguracji projektu.

- Końcowa kontrola po uporządkowaniu testów: Turnstile **90/90, 1359 asercji**;
  rejestracja z zaproszenia **13/13, 95 asercji**. Pint: sześć zmienionych plików PHP bez uwag.

## Granice i wycofanie

Nie zmieniono `turnstile.miejsca`, wymogu tokenu, zachowania przy awarii API,
wykluczania haseł z sesji ani schematu bazy. Wycofanie: odwrócić commit;
nie ma migracji ani danych do odtwarzania.

Bez testowania produkcji i prawdziwego widgetu w przeglądarce: pomiar dotyczy
HTTP aplikacji i renderowania Blade, a Cloudflare jest zastąpiony kontrolowaną
odpowiedzią. Bez wysyłania wiadomości, pushowania i PR-a, zgodnie z poleceniem.
Nie ma nowej decyzji produktowej do podjęcia przez właściciela.