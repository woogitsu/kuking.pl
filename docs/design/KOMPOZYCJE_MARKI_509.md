# Kompozycje marki — issue #509

Źródło: oryginalny ZIP zachowany przy audycie #508, widoki `logowanie`,
`rejestracja`, `przepis`, `profil` oraz sekcja `home-ownership` na Start.
Aktualne decyzje zachowują garnek, Inter, prawdziwe dane i działające formularze.

## Zakres

| Ekran | Poprawka | Zachowane zachowanie |
|---|---|---|
| Logowanie / rejestracja | Zaproszenie obok karty formularza | Hasło, link e-mail, dostępni dostawcy, zaproszenia, CSRF, błędy |
| Przepis | Tekst i zdjęcie obok siebie, akcje poniżej | Podgląd zdjęcia, zapis, gotowanie, obserwowanie, moderacja i widoczność |
| Profil własny / cudzy | Awatar 170 px, liczby pod nagłówkiem | Jedna kopia prawdziwych liczb; filtrowanie i akcje według odbiorcy |
| Strona publiczna | Trzy karty własności | Zeszyty, wybór widoczności, uczciwa informacja o eksporcie |

## Odbiór w toku

Testy docelowych obszarów lokalnie: 34 testy, 313 asercji, sukces.
Pomiary przeglądarkowe, kontrole ujemne, pełna bramka i wdrożenie pozostają
w toku. Ten zapis nie jest potwierdzeniem wdrożenia Alfa 0.18.

`scripts/kompozycje-marki.mjs` jest uruchamiany przez istniejący pomiar portu:
6 szerokości, 2 motywy, skale 100/140 i emulacja podwojonej czcionki
przeglądarki. Ostatni wariant nie jest rzeczywistym zoomem strony 200%.
Kontrole ujemne zmieniają prawdziwe arkusze, odbudowują Vite i sprawdzają
odtworzenie MD5 oraz ponowny poprawny pomiar. Zrzuty danych demonstracyjnych
trafiają do artefaktu CI, nie zastępują oglądu produkcji.

Pełny audyt marki nadal obejmuje ograniczenia z AUDYT_PACZKI_MARKI_508.md.
Odkrywanie i zeszyty wymagają osobnego porównania gęstości na rzeczywistych
treściach. Nie zastępujemy strumienia statyczną siatką z fikcyjnymi przepisami.
