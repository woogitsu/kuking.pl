## D-1009-ROBOCZA — Pierwszy wkład jest jednorazowym zdarzeniem (21 września 2026)

Numer ostateczny przydziela koordynator przy scalaniu. Właściciel rozstrzygnął
wprost: pierwszy wkład nie powtarza się po usunięciu wpisu. Zatwierdził także
odtworzenie tylko na podstawie zachowanych danych, bez zaległych alertów;
pełna gwarancja zaczyna się od wdrożenia.

Pamięć należy do autora, nie do powiadomienia ani aktualnego gospodarza.
`first_post_events` utrwala jeden nośnik; usunięcie go pozostawia zdarzenie,
zmiana gospodarza nie wywołuje reemisji. Inny moderator zobaczy oznaczenie
tylko przy tym nośniku i tylko jeśli ma dostęp. Nie dostaje alternatywnego
„pierwszego” publicznego wpisu, gdy nośnik był followers poza jego zasięgiem.
Bez gospodarza pierwszy publiczny wkład zużywa pierwszeństwo bez alertu;
followers bez dostępnego odbiorcy nie zużywa go. Historia jest odtwarzana
najpierw z alertów, potem z zachowanych dostępnych wpisów, w tym soft-deleted.

Publikacja serializuje autora po blokadach mediów (D-103), przed INSERT.
Wpis, znacznik, audyt, alert i enqueue są jedną transakcją. Standardowy
dispatch pozostaje: gwarancja trwałego enqueue dotyczy database queue na
identycznym obiekcie połączenia. Odmienny connection database odmawia przed
zapisem; sync/fake zachowują dotychczasowy kontrakt testowy, nie stanowią
dowodu trwałości. Zlecenie `low` ma beforeCommit, worker widzi je po commit.
Nie naprawiamy historycznych częściowych publikacji z #935 ani retencji.
Rollback jest wąsko chroniony zgodnie z D-088 (szczegóły: DATABASE.md).
