# Równoczesna edycja notatki w zeszycie (#2400)

Formularz właściciela i współtwórcy niesie odcisk wartości notatki widzianej przy otwarciu strony. Akcja ponownie sprawdza dostęp po zablokowaniu konta i zeszytu (#2311), a następnie pod blokadą pozycji porównuje odcisk z aktualną wartością. Rozróżnia `NULL` od pustego tekstu. Nie wymaga nowej kolumny ani historii edycji (D-302).

Jeśli ktoś zapisał zmianę wcześniej, stare okno nie nadpisuje jej. Szkic pozostaje w polu, uprawniona osoba widzi obecną notatkę i może świadomie kliknąć „Zastąp obecną notatkę”. Nowy odcisk pochodzi z aktualnej notatki; jeśli w międzyczasie zmieni się ponownie, operacja znów odmówi. Osoba bez dostępu nie widzi ani tekstu notatki, ani komunikatu konfliktu: Policy jest sprawdzana przed porównaniem.

Odcisk nie jest uprawnieniem. Jest tylko warunkiem spójności dla osoby, której Policy już pozwala edytować. Wartość odcisku nie trafia do logów, adresu ani powiadomienia. Brak lub nieprawidłowy odcisk odmawia zapisu i prosi o odświeżenie strony.

Test regresyjny otwiera dwie karty, zapisuje B z pierwszej, odmawia nadpisania C z drugiej, sprawdza zachowanie szkicu i świadomy drugi zapis. Osobny przypadek obejmuje współtwórcę i wyczyszczenie notatki; istniejący test dwóch połączeń obejmuje odebranie dostępu. Kontrola ujemna usuwa porównanie odcisku w akcji: test ma oblać się na zapisanej wartości z markerem `NOTATKA_2400_STARA_KARTA_NIE_NADPISUJE`, a po odtworzeniu kodu przejść.

Rollback kodu przywraca poprzednie zachowanie bez zmiany schematu. Przed wycofaniem trzeba uwzględnić, że otwarte formularze z nowym polem trafią do starszej wersji akcji; nie ma danych do migrowania.
