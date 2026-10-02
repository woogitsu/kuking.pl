# Wejście HTTP testu checklisty składników (#2596)

CodeQL check `110680292102` wykrył odbicie parametru `porcje` z adresu
w atrybucie HTML lokalnego serwera testu przeglądarkowego. Serwer nasłuchuje
wyłącznie na `127.0.0.1` z losowym portem i nie jest częścią produkcyjnej
aplikacji, lecz nie powinien utrwalać błędnego wzorca obsługi wejścia.

Serwer jest teraz osobnym modułem wywoływanym przez niezmieniony test DOM.
Przed renderowaniem zamienia `porcje` na liczbę i odmawia, jeśli nie jest
dodatnią bezpieczną liczbą całkowitą. Test HTTP bez Chromium potwierdza
poprawne wartości 4 i 8 oraz odmowę ciągu zawierającego HTML. Kontrola
ujemna uruchamia kopię tego samego modułu z wyłączoną odmową: test musi
oblać się na statusie 400 z markerem `FIXTURE_2596_ODMAWIA_HTML`, potem
oryginał ma przejść ponownie. Oba kroki są wykonywane w istniejącym CI.

Rollback: cofnięcie commitu przywraca serwer wpisany wprost w teście DOM,
łącznie z dawnym odbiciem parametru. Nie zmienia to kodu produkcyjnego,
schematu ani danych użytkowników.
