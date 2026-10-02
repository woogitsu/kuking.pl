# Prywatność wspólnego zeszytu — #2601

Edycja używa nazwy „Prywatny”, a nie obietnicy „Tylko ja”. Zmiana
publicznego zeszytu na prywatny odcina publiczny dostęp, ale zgodnie z D-302
nie usuwa współtwórców ani nie odwołuje zaproszeń. Wyjaśnienie jest widoczne
przed zapisem; „Kto ma dostęp” prowadzi do istniejącego ekranu zarządzania.
Potwierdzenie zapisu opisuje ten sam skutek. Domyślnego zeszytu nie można
współdzielić, więc nie dostaje wskazówki o zaproszeniach.

Test HTTP obejmuje ważnego członka oraz ważne, wygasłe i odwołane
zaproszenia, a także zeszyt bez zaproszeń. Sprawdza zachowanie zapisów,
notatek i zaproszeń, odmowę dla obcego oraz dostęp członka. Kontrola ujemna
przywraca dawną fałszywą obietnicę w potwierdzeniu i ma oblać właściwy test.

Bez migracji, zmiany Policy lub dodatkowego zapytania o członków. Tekst
opisuje zasadę dostępu; nie wylicza osób, które aktualnie mogą otworzyć
poszczególne treści. Wycofanie: zwykłe cofnięcie commita; wróci nieprawdziwy
opis współdzielenia.
