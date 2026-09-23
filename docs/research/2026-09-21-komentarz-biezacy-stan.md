# Komentarz: aktualny dostęp w chwili zapisu (#1019, wąski element #1020)

## Zmiana

Poprzednia akcja korzystała z modeli przekazanych przez kontroler. Zmiana
publicznego celu na prywatny po autoryzacji nadal pozwalała utworzyć komentarz
i powiadomienie. Rzeczywisty BlockUser uruchomiony w trakcie oczekiwania
publikacji na advisory 8301 pozostawiał komentarz bez powiadomienia.

Teraz odkrycie identyfikatorów poprzedza transakcję, lecz nie jest autoryzacją.
`LockCommentContext` blokuje zależności, odczytuje je ponownie i dopiero wtedy
pyta istniejące polityki. Post używa `comment`, Recipe/CookedEvent używają
`view`, tak jak ich kontrolery. Piszący musi być aktualnie aktywny. Rodzic
i korzeń przechodzą dotychczasowy, ostrzejszy zakres `widoczneDla`, nie
szerszą politykę pojedynczego komentarza.

Jedyny komunikat odmowy znajduje się w `LockCommentContext::UNAVAILABLE`;
nie ujawnia, czy przyczyną była blokada, prywatność, moderacja czy usunięcie.
Wszystkie pięć wejść HTTP zachowuje tekst przy odmowie: zwykły komentarz
Post/Recipe/CookedEvent, podziękowanie za ugotowanie i odpowiedź moderatora
z panelu bez odpowiedzi. Ich dotychczasowe wstępne uprawnienia pozostają.

## Kolejność blokad

```text
unikalne konta posortowane po UUID — FOR NO KEY UPDATE
  → istniejące follows piszący → uczestnik — FOR SHARE, kolejność kont
  → przepis wykonania, jeżeli dotyczy — FOR NO KEY UPDATE
  → cel Post / Recipe / CookedEvent — FOR NO KEY UPDATE
  → wybrany rodzic i korzeń, unikalne UUID w kolejności — FOR NO KEY UPDATE
  → ponowne odkrycie identyfikatorów i świeża kontrola dostępu
  → istniejący advisory 8301 i deduplikacja
  → komentarz + do dwóch powiadomień
  → commit → analiza treści
```

Nie ma limitu czterech ani pięciu kont. CookedEvent z odpowiedzią może
obejmować pięć różnych osób: piszący, kucharz, autor przepisu, autor wybranego
komentarza i autor korzenia. Zmiana author_id, recipe_id lub parent_id podczas
czekania wycofuje całą próbę przed ponownym odkryciem. Maksymalnie trzy próby;
ponawiany jest wyłącznie `ChangedCommentDependencies`, nie deadlock ani timeout.

Punkt liniaryzacji to końcowa kontrola aktualnego dostępu pod kompletem
zamków przed zapisem. Przegrana publikacja nie tworzy ani komentarza, ani
jego powiadomień. Wygrana może zostać później ukryta przez istniejące filtry;
twarde usunięcie CookedEvent może później legalnie usunąć komentarze kaskadą.
Nie zmieniamy retencji, polityk ani typów usuwania.

Sama blokada kont nie chroni cofnięcia obserwowania: UnfollowUser wykonuje
DELETE follows bez zamka kont. Dlatego istniejący wiersz dostaje FOR SHARE.
Brak wiersza nie daje dostępu; jego późniejsze dodanie tylko rozszerza dostęp.

## Dlaczego nie FOR UPDATE na kontach

Moderacja bierze zgłoszenie, zapisuje ModerationAction z FK moderatora
i właściciela, zmienia cel, a następnie powiadamia m.in. zgłaszającego.
Układ `publikacja: konto → cel` przeciw `moderacja: cel → późny FK konta`
powoduje rzeczywiste **40P01**, gdy konto trzymane jest przez FOR UPDATE.
FK bierze FOR KEY SHARE, które jest zgodne z FOR NO KEY UPDATE, lecz nie
z FOR UPDATE. Model tego cyklu oraz dodatnia kontrola zostały zmierzone
przed implementacją. Nowy test wykonuje także pełne `decide()` rzeczywistego
kontrolera (bez middleware HTTP), ze zgłaszającym równym komentującemu.

Tabela konfliktów: [PostgreSQL — blokady wierszy](https://www.postgresql.org/docs/18/explicit-locking.html#LOCKING-ROWS).

BlockUser nadal korzysta z niezmienionego ZamekPary i tej samej kolejności
UUID. Nie zmieniamy ZamekKonta ani NotifyUser. Publikacja nie bierze zamka
tagów, mediów ani zgłoszenia, więc nie dodaje odwrotnej krawędzi do EditPost,
PublishRecipe i moderacji. Nie wolno wywoływać jej z już trzymanym dowolnym
zamkiem pojedynczego konta. Guard wykrywa **nazwany ZamekKonta**, nie potrafi
wykryć każdego ręcznego SELECT FOR UPDATE w obcej transakcji. Audyt pięciu
istniejących wywołań HTTP nie znalazł takiego zewnętrznego zamka.

## Usuwanie rodzica

Kontroler pozostaje cienki: nowa nazwana akcja `DeleteComment` bierze zamek
wiersza, ponownie autoryzuje i dopiero potem sprawdza `replies()->exists()`.
Jeśli publikacja odpowiedzi wygra, usuwanie zobaczy ją i zostawi placeholder.
Jeśli usunięcie bez odpowiedzi wygra, publikacja odmówi. Zakres relacji replies,
tekst placeholdera i zakres danych kasowanych pozostają bez zmian. Placeholder
nie jest traktowany jako ukryty lub soft-deleted rodzic; można nadal odpowiedzieć.

## Koszt i ograniczenia

Lokalny pojedynczy pomiar bez kontencji, z wyłączonym wykonywaniem zadania analizy:

| Fixture | Wszystkie zapytania SQL | Zapytania blokujące wiersze | Czas akcji / czas SQL |
|---|---:|---:|---:|
| Post, komentarz główny, 2 konta | 14 | 5 | 27,69 / 13,26 ms |
| CookedEvent, wybrana odpowiedź i osobny korzeń, 5 kont | 34 | 14 | 16,73 / 8,37 ms |

To nie benchmark ani obietnica produkcyjnej wydajności. Różne rozgrzanie
procesu i równoległy ruch lokalny uniemożliwiają porównanie tych czasów.
Zapytanie FOR SHARE do brakującego follows nie oznacza zablokowanego wiersza.
Koszt rośnie liniowo z liczbą uczestników. Komentarze współdzielące konto
lub cel czekają na siebie do końca krótkiej transakcji. Świadomie nie dokładamy
kolejki, cache ani nowego mechanizmu blokad, by ukryć ten koszt.

## Weryfikacja

`KomentarzSprawdzaSwiezyStanTest` sprawdza trzy cele, stare modele, stany
piszącego, prywatność, cofnięcie obserwowania, rodzica i korzeń, oba kierunki
blokady oraz osobnego autora przepisu wykonania. Dodatnie kontrole zachowują
własną treść, legalny placeholder, statusy kucharza oraz rzeczywiste wyjątki
moderatora: PostPolicy nie wpuszcza moderatora do cudzego hidden, Recipe
i CookedEvent mają swoje dotychczasowe wyjątki. Awaria drugiego powiadomienia
cofa cały zapis. Powtórzone wysłanie nadal zwraca ten sam komentarz.

`KomentarzBiezacyStanTest` wykonuje pełne akcje w osobnych procesach:
BlockUser w obu kierunkach, UnfollowUser, EditPost, PublishRecipe, DeleteComment
oraz decide moderacji. Obejmuje osobno autora przepisu CookedEvent, autora
rodzica, ukrycie/usunięcie korzenia i pięć różnych osób. Prawdziwe bariery
PostgreSQL potwierdzają PID, SQL i blokujący backend. Limity pracowników:
lock 5 s, statement 8 s, oczekiwanie bariery 4 s, wynik procesu 12 s.
Późniejszy odczyt HTTP sprawdza filtrowanie po zwycięstwie publikacji i dodatnio
legalny placeholder. Osobne testy wymuszają zmianę grafu zależności oraz
moderację trzymającą cel przed późnym FK zgłaszającego.

Ścieżka edycji przepisu w tych wyścigach nie zawiera zdjęć. Nie jest to dowód
całego pipeline mediów ani ogólny dowód braku każdego możliwego zakleszczenia.
Lokalny pomiar dotyczy PostgreSQL i syntetycznych fixture, nie produkcji.

Grupa `dwa-polaczenia` jest domyślnie wyłączona. Uruchamia ją
`scripts/testy-dwa-polaczenia.sh` (albo `scripts/check.sh --wyscigi`).
Historyczny job CI ma `continue-on-error: true`: zielony ogólny wynik CI
**nie dowodzi przejścia wyścigów**. Odbiór musi obejmować konkretny wynik tego
joba oraz zwykłe testy. Nie zmieniamy tej polityki CI przy okazji.

## Zapis lokalnego odbioru

- Powiązane testy funkcjonalne: 219 testów, 2605 asercji; PHPStan bez błędów.
- Cała grupa dwóch połączeń, również wcześniejsze testy: 97 testów,
  1423 asercje. Nie zastępuje to pełnego zwykłego zestawu testów.
- Cztery kontrole przez niezmieniony `scripts/kontrola-ujemna.sh`:
  usunięcie świeżej Policy i filtra rodzica powoduje nadmiarowy komentarz;
  FOR UPDATE zamiast NO KEY UPDATE powoduje rzeczywisty SQLSTATE 40P01
  podczas pełnej moderacji; usunięcie zamka DeleteComment usuwa rodzica
  zamiast zachować legalny placeholder. Każda przeszła PASS–FAIL z właściwą
  przyczyną–PASS. Sprawdzono przywrócenie MD5 i mtime.
- JSON przyrządu powstaje przed jego końcowym trapem i pozostawia pole
  `przywrocenie` jako „nie wykonane”. Osobny readback po zakończeniu
  potwierdza `restored: true`, MD5 oraz dokładne mtime; nie poprawiano
  przyrządu ani jego historycznego wyniku.
- Zachowano także próby niediagnostyczne: błąd cytowania sondy rodzica
  i pierwszą mutację FOR UPDATE, której obserwator szukał niewłaściwej
  nazwy zamka. Nie są dowodem regresji. Poprawiona sonda potwierdziła 40P01.
- Pierwsza seria stabilności: 13 PASS, następnie błąd fixture 23505
  `users_email_unique`, nie deadlock. Nowe fixture używają losowych adresów
  tylko w tej klasie testu. Druga seria: 20 PASS po 14 scenariuszy; podczas
  niej dodano asercję, dlatego ostateczny odbiór wymaga osobnej zamrożonej serii.
- Ostateczna zamrożona seria: **20/20 PASS**, każdy przebieg 18 testów
  i 260 asercji, łącznie 360 wykonań scenariuszy / 5200 asercji.
  Wybrano trzy pełne moderacje z późnym FK zgłaszającego, trzy zmiany grafu
  zależności, oba porządki Post/unfollow i Post/parent_remove oraz oba
  kierunki i porządki Cooked/recipe_block i Cooked/root_block.
  Nie zmieniano aplikacji ani testów podczas tej serii.

Artefakty lokalne znajdują się poza repozytorium w katalogu floty `_wspolne`:
`codex1019-related-final.log`, `codex1019-final-races.log`,
`codex1019-official-negative.log`, cztery pary JSON kontroli i readbacku oraz
logi `codex1019-stability-*`. Zachowany niezależny przegląd
`CODEX_1019_PRZEGLAD_PROTOKOLU.md` jest odczytem wcześniejszego kodu, nie
zamiennikiem odbioru końcowego SHA. Pełną zwykłą bramkę odbiera koordynator.

## Wycofanie zmiany

Brak migracji. Wycofanie zmian aplikacji przywraca starą ścieżkę komentarzy
i znany wyścig; nie wymaga modyfikacji danych. Nie usuwać legalnych komentarzy
ani powiadomień powstałych przed zmianą. Wyniki końcowych przebiegów i kontroli
ujemnych należy odebrać dla tego samego commita co kod.
