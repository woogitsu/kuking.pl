# Trzy piloty AI: pomiar bez dostawcy

Data: 20 września 2026. Zgłoszenia: #815, #813, #814; granica danych: #912.
Punkt wyjścia: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, gałąź `gpt/ai-piloty`.

## Wynik i zakres decyzji

**Nie ma jeszcze podstaw do wdrożenia AI.** Nie wykonano żadnego żądania do
modelu. Właściwy proces WSL nie ma klucza API; po wyjaśnieniu tego ograniczenia
praca jest kontynuowana bez klucza. Wcześniejsze sprawdzenie jego obecności
było błędne wskutek cytowania polecenia powłoki. Uruchomienie właściwego
przyrządu zatrzymało się przed połączeniem, nie zużyło środków.

Jest podstawa do dalszego porównania: w #815 kilka prostych reguł bez AI daje
trafny wynik dla 40/60 pytań obsługiwalnych przez obecne filtry, wobec 31/60
dla tekstu przekazanego bez przekształcenia. To wynik na przygotowanych danych,
nie ocena produkcji ani dowód przewagi nad modelem. Pozostałe 20 pytań nadal
nie daje wyników. Nie wdrożono nawet tych reguł — są wariantem eksperymentu.

Powstały lokalne przyrządy CLI, zbiory syntetyczne, testy HTTP na atrapach
i dowody pomiarów. Nie dodano tras, przycisków, zależności ani migracji.
Żaden szkic nie jest zapisywany do bazy. Zgłoszenia pozostają eksperymentami
nieukończonymi w części wymagającej odpowiedzi modelu i oceny ludzi.

## Co zmierzyłem sam

Przed zmianami: `SearchTest` — 12 testów, 34 asercje;
`SzukajWidocznoscTest` — 9 testów, 24 asercje. PostgreSQL na
`127.0.0.1:55439`, izolowana baza `kuking_flota_gpt-ai-piloty`.
Wyniki poniżej pochodzą z własnych uruchomień. Nie przejęto cudzych liczb.

### #815 — punkt odniesienia bez AI

Zbiór zawiera 100 syntetycznych pytań: 60 z ręcznie określonym oczekiwanym
wynikiem w istniejących możliwościach, 30 poza tym zakresem, 10 niejasnych.
Baza pomiarowa: 105 przepisów (35 tytułów w trzech wariantach czasu:
20 minut, 60 minut, brak czasu) i profile testowe. Użyto prawdziwego
`SearchQuery`, z autoryzacją i filtrowaniem widoczności. Każde pytanie
uruchomiono raz na wariant; brak rozgrzewki i wielokrotnych powtórzeń oznacza,
że różnic czasowych nie należy interpretować jako trwałego przyspieszenia.

| Miara | A: oryginalny tekst, wszystko | B: proste reguły |
|---|---:|---:|
| Pytania wykonane | 100 | 100 |
| Pytania obsługiwalne z co najmniej jednym trafnym wynikiem | 31/60 | 40/60 |
| Puste wyniki w tej grupie | 29/60 | 20/60 |
| Średni udział trafnych wyników w niepustych odpowiedziach | 85,81% | 87,00% |
| Mediana czasu lokalnego wyszukiwania | 21,198 ms | 19,142 ms |
| P95 czasu lokalnego wyszukiwania | 34,314 ms | 32,144 ms |
| Zapytania SQL łącznie | 456 | 278 |
| Żądania do dostawcy | 0 | 0 |

Oceniono maksymalnie pięć przepisów i pięć profili, jako osobne listy.
Trafność jest zdefiniowana w `search-relevance.json`; nie jest oceną ludzi.
Przykładowo etykieta dla „pyry” uznaje ziemniaki z piekarnika, ale nie placki
ziemniaczane. Wynik zależy od takich jawnych, zawężających wyborów oceniającego.
Nie wolno zamienić ich w wymagania produktu bez decyzji właściciela.

Reguły B ustalono przed pomiarem: początkowe `@` wybiera ludzi, dosłowne
„do 30 minut” wybiera istniejącą sekcję szybkich przepisów, „pyry” i „kartofle”
zamieniane są na „ziemniaki”, „kabaczek” na „cukinia”; domyślnie przepisy.
To nie jest rozumienie języka ani obsługa dowolnego limitu czasu.

Przykłady: „pyry”, „kabaczek”, „naleśniki do 30 minut” i „@basia” przestają
dawać pusty wynik. „szukam osoby Basia” oraz „szukom modrej kapusty” nadal
zawodzą w obu wariantach. Zapytania o alergeny nie otrzymują gwarancji
bezpieczeństwa z żadnego z tych wariantów.

Dowody: [surowe 200 pomiarów](evidence/sql-baseline.json),
[podsumowanie](evidence/sql-summary.json), [ocena każdego pytania](evidence/sql-assessment.json).

### #814 — prawdziwe teksty i zakaz dopisywania

Źródło wskazane przez właściciela: [Odkryj](https://kuking.pl/odkryj).
Pobrano 15 publicznych tekstów z ich stron wpisów, bez zdjęć i danych profili,
bez podążania za linkami do cudzych blogów. Same kopie tekstów pozostają
lokalnie, poza gitem; dowód zawiera adres źródła i SHA-256 tekstu po usunięciu
odnośników. Zbiór nie jest reprezentatywną próbą społeczności.

To **nie jest 15 pełnych przepisów**: 11 tekstów to głównie podpisy,
jeden jest krótką uwagą o zamianie składnika, trzy zawierają rozbudowany opis.
Zakres długości: 13–2051 znaków. „Rolada kawowa” nie wystarcza do odtworzenia
składników. W opisie kaszy manny jest alternatywa „szklanka cukru(dalam pol
szkl. stevii)”; nie wolno rozstrzygać jej za autora. W długim opisie ciasta
świątecznego występuje znak zastępczy Unicode — zachowano go bez zgadywania.

Model w tym eksperymencie może zwrócić **wyłącznie końce kolejnych fragmentów
i etykietę**: składnik, krok, uwaga, do sprawdzenia. Nie ma pola na wygenerowany
tekst, ilość ani liczbę porcji. PHP odtwarza fragmenty z oryginału, w tej samej
kolejności i z pełnym pokryciem. Obce pola, pominięcia i indeksy poza tekstem
są odrzucane. Brak ilości pozostaje brakiem. Wynik zawiera oryginał i znacznik
wymaganego zatwierdzenia; przyrząd nie posiada drogi zapisu przepisu.

Na 50 tekstach syntetycznych i 15 publicznych ręczna atrapa zachowała
65/65 oryginałów. Cztery celowo wadliwe propozycje na tekst zostały odrzucone:
260/260, w tym 60/60 na tekstach publicznych. **To test integralności,
nie wynik jakości AI.** Atrapa oznacza cały tekst jako „do sprawdzenia”.
Nie sprawdzono, czy model właściwie dzieli zdania, zachowuje znaczenie negacji
i alternatyw ani czy jego szkic oszczędza autorowi pracę. Pełne pokrycie tekstu
nie dowodzi poprawnej interpretacji. Zabezpieczenie nie obiecuje automatycznego
przepisania tekstu do istniejącego kreatora z jego autosave.

### #813 — katalog pomocy

Przygotowano 100 syntetycznych pytań i katalog 10 intencji. Kod wiąże intencję
z istniejącą trasą i stałym tekstem; model nie może podać własnego URL-a
ani HTML-a. Test sprawdza istnienie tras. Trafność klasyfikacji modelu,
czas znalezienia funkcji i badanie z 5–8 osobami pozostają niezmierzone.

## Koszt i granica danych #912

| Zbiór | Liczba | Bajty kompletnego żądania, min–max | Żądania wysłane |
|---|---:|---:|---:|
| Pytania do wyszukiwarki | 100 | 1434–1484 | 0 |
| Pytania o pomoc | 100 | 1360–1398 | 0 |
| Syntetyczne opisy | 50 | 1723–2020 | 0 |
| Publiczne teksty | 15 | 1723–11646 | 0 |

Rozmiary zmierzono na rzeczywistym serializowanym JSON-ie z instrukcją,
schematem i numeracją tokenów. [Dowód dla 265 wejść](evidence/input-sizes.json).
Faktyczny koszt dostawcy w tej sesji: **0 USD przy 0 żądaniach**.
Koszt odpowiedzi, opóźnienie sieci/modelu i zużycie tokenów: **niezmierzone**.
Koszt infrastruktury lokalnej nie był wyceniany.

Przygotowany klient ma stały model `gpt-5.4-nano-2026-03-17`, stały adres
Responses API, `store: false`, bez narzędzi, zdjęć, profili ani adresów źródeł.
Limit wejścia to 600 znaków pytania lub 4000 znaków opisu oraz 24 000 bajtów
całego żądania. Przekroczenie zatrzymuje wysyłkę; tekst nie jest obcinany.
Wyjście: maksymalnie 256 tokenów dla pytań, 1600 dla opisu. Połączenie ma
limit 3 s, całe żądanie 10 s; brak przekierowań i ponowień. Limit odpowiedzi
64 KB sprawdzany jest po pobraniu, nie jest limitem strumieniowania.

Trwały, blokowany licznik rezerwuje 0,01 USD na próbę, najwyżej 500 prób
(koperta 5 USD przy wskazanym cenniku i limitach). Nie zwraca rezerwacji po
błędzie ani braku danych rozliczeniowych. Rzeczywisty koszt jest liczony
z `usage`; brak `usage` daje brak kosztu, nie zero. To lokalny hamulec
eksperymentu, nie limit konta u dostawcy ani rozwiązanie produkcyjne.

[Cennik modelu](https://developers.openai.com/api/docs/models/gpt-5.4-nano)
odczytany przy przygotowaniu: 0,20 USD / milion tokenów wejścia,
0,02 USD dla wejścia z cache, 1,25 USD dla wyjścia. Przed przyszłym pomiarem
trzeba ponownie sprawdzić cennik. `store: false` nie stanowi obietnicy zerowej
retencji u dostawcy — zobacz [zasady danych](https://developers.openai.com/api/docs/guides/your-data).

## Weryfikacja i ograniczenia

Testy pilota: 32 przypadki, 93 asercje na PostgreSQL, HTTP wyłącznie na
atrapach z zakazem przypadkowych połączeń. Sprawdzono także brak zapisu
szkicu i odcięcie prywatnych, zablokowanych oraz nieopublikowanych przepisów.
Cztery kontrole ujemne dały zielono → czerwono po usunięciu zabezpieczenia
→ zielono po przywróceniu: pełne pokrycie źródła, zamknięte pola,
limit bajtów, limit rezerwacji. Dodatnie przypadki sprawdzają, że poprawny
tekst i poprawny szkic naprawdę są odczytywane i przyjmowane.

Pliki `control-*.json` zapisane przez istniejący pomocnik mają pole
`przywrocenie` sprzed jego końcowej pułapki EXIT. Nie należy odczytywać tego
pola jako wyniku końcowego przywrócenia. Pomocnik dodatkowo sprawdza MD5
i czas modyfikacji po odtworzeniu. Stan zbiorczego uruchomienia testów
zapisano osobno w `WERYFIKACJA.md`.

## Decyzje właściciela

| Wariant | Co daje | Koszt / czego jeszcze potrzeba |
|---|---|---|
| Zostać przy obecnych funkcjach | Brak nowego kosztu i wysyłania tekstów | AI pozostaje niewdrożone |
| Rozważyć proste reguły #815 | Zmierzona poprawa na małym zbiorze | Osobna decyzja o zachowaniu wyszukiwarki, szersze dane i testy UX |
| Później uruchomić porównanie modelu | Pomiar trafności, tokenów, czasu i błędów | Dostęp API; maks. 500 prób w lokalnej kopercie 5 USD; ręczna ocena i powtórzenia |
| Kontynuować #814 jako wybór fragmentów | Mechaniczny zakaz dopisywania słów | Ocena sensu podziału, zgoda na taki zakres produktu, projekt jawnego przyjęcia szkicu |
| Kontynuować #813 | Potencjalnie łatwiejsze znalezienie funkcji | Pomiar modelu i badanie 5–8 osób; katalog pomocy sam nie dowodzi użyteczności |

Nie wykonano badania ludzi, porównania dostawców, pomiaru produkcyjnego SQL,
wdrożenia UI ani testu prawdziwego modelu. Nie otwarto PR-a i nie wykonano
push. Brak klucza nie blokuje tych dostarczonych pomiarów, ale bez odpowiedzi
modelu nie da się rzetelnie orzec „AI warto / nie warto”.
