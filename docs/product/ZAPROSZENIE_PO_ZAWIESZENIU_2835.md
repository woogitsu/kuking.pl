# Zaproszenie do zeszytu a zawieszenie właściciela (#2835)

`CollectionPolicy::share()` dopuszcza nowe zaproszenie wyłącznie aktywnego
właściciela zwykłego zeszytu. Początkowa kontrola HTTP i domeny nie wystarcza:
moderator może zatwierdzić zawieszenie w trakcie już rozpoczętego żądania.

Obie drogi zaproszenia ponownie sprawdzają `share` na świeżym właścicielu
i świeżym zeszycie pod blokadami, zanim zmienią oczekujące zaproszenia,
wygenerują token albo zapiszą powiadomienie. Po nazwie istniejący `ZamekPary`
blokuje oba konta; link blokuje konto przez `ZamekKonta`. Dopiero potem
blokowany jest zeszyt. Ta kolejność jest wspólna z przyjmowaniem zaproszeń.
Zawieszenie korzysta z tego samego zamka konta: pierwsza ukończona operacja
wyznacza wynik. Sankcja pierwsza oznacza odmowę bez nowego wiersza. Zaproszenie
pierwsze może skończyć się przed sankcją; wcześniejsze zaproszenia nie są
automatycznie odwoływane. Zawieszony właściciel nadal może zobaczyć listę
dostępu i odwołać zaproszenie zgodnie z D-302; ta poprawka tego nie zmienia.

Dowód: `ZawieszenieNieTworzyZaproszeniaDoZeszytuTest` sprawdza stare modele
i normalne trasy HTTP. `ZaproszeniePoZawieszeniuNaDwochPolaczeniachTest`
w izolowanej bazie PostgreSQL 18 mierzy oba przeploty dla obu dróg.
`scripts/kontrola-negatywna-2835.py` wyłącza powtórną Policy, wymaga dwóch
porażek z własnym markerem, a potem przywraca bajty i mtime i wymaga ponownie
czterech zielonych przypadków. Kontrola jest częścią zadania CI
`testy-dwa-polaczenia.sh`.

Nie ma migracji ani nowego sposobu wygasania kar. Wycofanie kodu przywróciłoby
opisany wyścig, więc przed wycofaniem trzeba zapewnić równoważną kontrolę
świeżego stanu konta i utrzymać testy obu kolejności.
