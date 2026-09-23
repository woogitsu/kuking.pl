# Spójny zapis przepisu do zeszytu — #907

Zapis powiązania i powiadomienie `recipe.saved` zatwierdzają się w jednej
transakcji PostgreSQL. Błąd powiadomienia wycofuje próbę; ponowienie tworzy
powiązanie i wiadomość. Istniejąca para zeszyt/przepis jest znacznikiem
zakończenia, niezależnym od retencji samych powiadomień. Celowe pominięcie
wiadomości przez NotifyUser (np. własny przepis) nadal kończy zapis sukcesem.

Wzorzec odczytano w `e89f28a5` i w `gpt/eksport`: trwały skutek i znacznik
muszą zatwierdzić się razem. Tutaj oba skutki są lokalnymi zapisami tej samej
bazy, więc nie ma potrzeby tworzyć zadania ani drugiego mechanizmu kolejki.
Eksport wymaga osobnego zadania dla zewnętrznej wysyłki; ta akcja jej nie robi.
Transakcja przy attach stanowi savepoint: konflikt unikalności nie pozostawia
zewnętrznej transakcji PostgreSQL w stanie przerwanym.

Pomiar własny na bazowym `4c811cc7`: CHECK odrzucający prawdziwy INSERT
powiadomienia dał HTTP 500, jeden zapis i zero powiadomień także po ponowieniu.
Ten sam test po zmianie: po błędzie zero zapisów, po ponowieniu jeden zapis
i jedno powiadomienie, po kolejnym ponowieniu nadal jedno powiadomienie.

Nie naprawiamy historycznych częściowych zapisów: brak wiadomości może
wynikać z retencji lub reguł odbiorcy, więc nie dowodzi awarii. Ewentualna
naprawa danych wymaga osobnego audytu i decyzji właściciela.

Brak zmiany schematu. Wycofanie: revert commita przywraca poprzedni kod,
bez przekształcania danych, ale przywraca też ryzyko częściowego zapisu.
Semantyka wielu zeszytów pozostaje osobnym pytaniem #906.