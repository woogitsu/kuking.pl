# Dopisek otwarty ze spisu kroków (#2858)

Link „Spis kroków” otwiera podgląd wybranego kroku z `?spis=1`. Sam podgląd
ostatniego kroku nie jest ukończeniem gotowania i nie uruchamia pytania
„Jak wyszło?” na Starcie.

Formularze prywatnego dopisku przenoszą tylko zamknięty znacznik `spis=1`.
Powrót po zapisie, odmowie walidacji, konflikcie, awarii zapisu i usunięciu
prowadzi do tej samej lokalnej trasy, z wybranym krokiem i porcjami. Adres
powrotu od klienta nie jest używany. Zwykłe przejście do ostatniego kroku
bez znacznika nadal uruchamia pytanie; wcześniej prawidłowo utworzone pytanie
nie jest usuwane przez późniejszy podgląd.

Regresja `DopisekZeSpisuKrokowTest` czyta rzeczywisty link i formularz, wykonuje
POST oraz GET po przekierowaniu i sprawdza Start, sygnał i brak publicznego
wykonania. Dwie kontrole ujemne w istniejącym rejestrze CI usuwają znacznik
osobno z formularza i z powrotu. Cofnięcie commitu przywraca dawny adres
powrotu i dawny błąd; nie zmienia schematu ani zapisanych dopisków.
