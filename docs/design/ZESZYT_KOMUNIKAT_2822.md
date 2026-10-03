# Koniec współtworzenia zeszytu (#2822)

Odebranie członkostwa i samodzielne odejście kończą możliwość zapisywania w
zeszycie. Nie zmieniają widoczności zeszytu. Były współtwórca może nadal
czytać zeszyt publiczny, jeśli aktualna autoryzacja na to pozwala; zeszyt
prywatny nie jest już dla niego dostępny. Blokada lub moderacja mogą odebrać
także publiczny odczyt, dlatego potwierdzenie i komunikat nie obiecują go
bezwarunkowo. Wcześniej dodane pozycje zostają.

Regresja HTTP `WspolnyZeszytTest::test_koniec_wspoltworzenia_nie_obiecuje_utraty_publicznego_widoku`
sprawdza odejście i odebranie członkostwa osobno dla zeszytu publicznego i
prywatnego: komunikat, potwierdzenie, późniejszy odczyt oraz odmowę nowego
zapisu. Kontrole ujemne przywracają dawną obietnicę w komunikacie i w
potwierdzeniu; każda musi oblać własny znacznik. Cofnięcie zmiany przywraca
stare, nieprawdziwe teksty, ale nie zmienia uprawnień ani danych.
