# Budżet czekania na bramkę — ustalony PRZED rozpoczęciem rampy

Zapisane 18.09.2026, **zanim ruszyła pierwsza sekunda czekania**. Liczba stoi
tu po to, żeby nie dało się jej później naciągnąć do tego, co akurat wyszło.

## Liczby

| co | ile |
|---|---:|
| stopnie rampy | 5 → 15 → 30 → 50 → 80 → 120 → 170 żądań/s |
| czas jednego stopnia pod obciążeniem | 120 s |
| próbkowanie po zdjęciu obciążenia (powrót do normy) | 60 s |
| maksymalne czekanie bramki na JEDEN stopień | **300 s** |
| prób na stopień (skażony → powtórka) | **2** |
| przerwa między stopniami | 30 s |
| **całkowity budżet rampy** | **90 minut** |

Po przekroczeniu 90 minut rampa kończy się tym, co zdążyła zdjąć. Stopnie
nierozpoczęte i nieudane zapisują się jako **NIEWYKONANE z powodem**.

## Dlaczego akurat tyle

- **300 s na bramkę przy jednym stopniu.** Rozpoznanie obcego obciążenia
  (`rozpoznanie-obcego.csv`) pokazało, że najdłuższy ciąg spełniający próg
  18 rdzeni / PSI 25 % trwał **70 s** w oknie 4,6 minuty. Jeśli w ciągu pięciu
  minut nie trafi się okno 60-sekundowe, to znaczy, że maszyna jest w fazie
  gęstego CI, a nie że „trzeba jeszcze chwilę".
- **2 próby, nie 5.** Trzecia i czwarta próba tego samego stopnia w tych samych
  warunkach nie niosą nowej informacji, a zjadają budżet innych stopni.
  Lepiej mieć trzy stopnie uczciwe i cztery oznaczone „niewykonany" niż jeden
  stopień powtarzany do skutku.
- **90 minut na całość.** Siedem stopni × (300 s bramki + 120 s stopnia + 60 s
  wybiegu + 30 s przerwy) ≈ 60 minut przy jednej próbie na stopień. Zapas do 90
  minut pokrywa powtórki dwóch–trzech stopni. Powyżej tego czasu rośnie ryzyko,
  że pierwsze i ostatnie stopnie zdjęto w nieporównywalnych warunkach.

## Co, jeśli bramka nie przepuści niczego

Wynikiem jest wtedy zdanie, nie liczba:

> Rampy nie udało się zdjąć 18 IX 2026. Bramka nie otworzyła się przez N minut
> oczekiwania przy progu 18,0 rdzenia / PSI 25 % / zero pracujących runnerów
> `kuking`. Obce obciążenie pochodziło z CI czterech obcych projektów na wspólnym
> hoście, mediana Y rdzenia. Przyrząd, zbiór danych i metoda są gotowe; pomiar
> wymaga maszyny, która nie jest hostem CI, albo okna uzgodnionego z właścicielami
> pozostałych projektów.

Taki wiersz mówi właścicielowi, czego brakuje i co uzgodnić, żeby pomiar dało
się w ogóle wykonać. Wymyślony punkt nasycenia nie mówi nic, a szkodzi.
