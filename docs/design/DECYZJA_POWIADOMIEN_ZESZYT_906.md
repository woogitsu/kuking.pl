# #906 — kiedy autor dowiaduje się o zapisaniu przepisu

Status: **do decyzji właściciela**, nie przyjęta reguła. Ta gałąź nie dodaje
asercji ustalającej liczbę powiadomień między różnymi zeszytami i nie zmienia
reguł „Ugotowałem”.

Autor widzi dziś tekst: „{osoba} ma Twój przepis „{tytuł}” w swoim zeszycie.”
Nie widzi nazwy ani zawartości prywatnego zeszytu. Dwie takie wiadomości
od tej samej osoby wyglądają identycznie poza czasem.

| Wariant | Skutek dla autora | Koszt i konsekwencje |
|---|---|---|
| A. Każde nowe powiązanie zeszyt/przepis | Kolejne powiadomienie przy zapisie w B; powtórzenie A nic nie dodaje. Porządkowanie daje identyczne wiadomości. | Najniższy: utrzymanie obecnej reguły. Trzeba świadomie zaakceptować powtórzenia, także po wyjęciu i ponownym zapisie. |
| B. Pierwszy aktualny zapis osoby | Jedna wiadomość, dopóki osoba ma przepis w dowolnym zeszycie. Wyjęcie ze wszystkich i ponowny zapis rozpoczyna kolejny okres. | Średni: wspólna blokada dla pary osoba/przepis, atomowe sprawdzenie wszystkich zeszytów, przegląd każdej drogi usuwania i test dwóch połączeń. Samo exists przed attach nie wystarczy. |
| C. Pierwszy zapis osoby w historii | Najwyżej jedna wiadomość od danej osoby o przepisie, także po skasowaniu wszystkich jej zapisów i powrocie po roku. | Wyższy: trwały znacznik osoba/przepis z indeksem unikalnym, migracja, zasady retencji i usunięcia konta. Powiadomienie nie może być znacznikiem, bo podlega retencji. Historycznych braków nie wolno odgadywać. |
| D. Okno czasowe | Powtórzenia w obrębie okna milczą, późniejsze znów powiadamiają. | Średni: właściciel musi wybrać długość i uzasadnienie okna; potrzebna atomowa deduplikacja, kontrola wyścigu i jasne zachowanie po retencji. Czas sam nie rozróżnia porządkowania od nowego zainteresowania. |

Rekomendacja do rozważenia: **B**, jeżeli powiadomienie ma oznaczać
„ta osoba zachowała mój przepis”, a nie każdą czynność porządkowania.
Wymaga osobnego rozstrzygnięcia, czy ponowne zapisanie po wyjęciu ze
wszystkich zeszytów rzeczywiście ma dawać nową wiadomość. Jeśli nie — C.
Nie dodawać nazwy prywatnego zeszytu, żeby odróżniać powtórzenia.

Pomiar SQL tej gałęzi zapisuje `output/pomiar-906.php` (jednorazowy próbnik,
nie test reguły); wyniki i stan kodu opisuje raport końcowy.