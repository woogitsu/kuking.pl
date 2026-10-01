## D-048 · Nowy adres e-mail obowiązuje po kliknięciu w link, a zajętość adresu rozstrzyga się dopiero tam

**Data:** 9 września 2026 · Issue #195 · Status: **obowiązuje**

Zmiana adresu e-mail w Kuking jest **zmianą stanu konta**, nie edycją profilu.
Idzie osobnym ekranem (`/ustawienia/e-mail`) i pełną drogą: obecne hasło →
list z podpisanym odnośnikiem na NOWY adres → kliknięcie → zmiana, plus
natychmiastowe ostrzeżenie na STARY adres. Do kliknięcia obowiązuje adres
dotychczasowy: logowanie i „nie pamiętam hasła" działają tak jak wczoraj.

**DLACZEGO NIE POLE W `/ustawienia/profil`.** Bo adres e-mail jest jedyną
drogą odzyskania konta — kto go przestawi, przejmuje konto resetem hasła.
Pole obok „bio", zapisywane jednym `PUT`, byłoby przejęciem konta na jedno
kliknięcie u każdego, kto usiadł przy niezablokowanej przeglądarce. Z tego
samego powodu `email` i `email_verified_at` wypadły z `User::$fillable` —
ta sama reguła co przy `status` i `role` (AGENTS.md §7).

**OCZEKUJĄCA ZMIANA MIESZKA W OSOBNEJ TABELI** (`pending_email_changes`),
nie w kolumnach na `users`. To nie jest cecha konta, tylko żądanie z własnym
życiorysem: powstaje, wygasa, zostaje skasowane albo skonsumowane. Wiersz
znikający w całości nie wymaga CHECK-a wiążącego nullowość dwóch kolumn,
nie obciąża najczęściej czytanej tabeli w bazie wartościami, które w 99,9%
wierszy są NULL-em, i znika jednym `DELETE`, a nie `UPDATE`-em na `users`.
Pełny wywód: migracja i `docs/DATABASE.md`.

**ADRES ZAJĘTY PRZEZ INNE KONTO NIE ODBIJA SIĘ W FORMULARZU** — i to jest
druga połowa tej decyzji. `Rule::unique('users','email')` w walidacji byłby
wyciekiem: zalogowany wpisuje dowolny adres i po odpowiedzi wie, czy ta osoba
ma konto w Kuking. Serwis, w którym da się sprawdzić, czy sąsiadka albo była
żona tu gotuje, nie jest bezpieczną izbą (`docs/product/SOUL.md`, filar
czwarty). Dlatego odpowiedź formularza jest identyczna dla adresu wolnego
i zajętego, żądanie powstaje w obu przypadkach, a o kolizji dowiaduje się
dopiero ten, kto **kliknie odnośnik** — czyli osoba czytająca pocztę pod tym
adresem, której i tak wolno wiedzieć, że ma u nas konto. Kosztem jest jeden
list wysłany „w próżnię"; zyskiem — brak wyroczni obecności konta.

**REJESTRACJA ZOSTAJE JAK BYŁA** i to nie jest niekonsekwencja do
posprzątania. `RegisterController` mówi wprost „na ten adres jest już
założone konto", bo tam ta odpowiedź jest jedyną drogą, żeby powiedzieć
człowiekowi „masz już konto, zaloguj się". Tam nie mamy wyboru, tutaj mamy
i wybieramy nieprzeciekającą stronę. Zmiana rejestracji to osobna decyzja
o osobnym ekranie.

**ZMIANA I RESET HASŁA UNIEWAŻNIAJĄ OCZEKUJĄCE ŻĄDANIE.** List ostrzegawczy
do starego adresu radzi „jeśli to nie Ty — zmień hasło", więc ta rada musi
być prawdziwa: bez tego napastnik dokończyłby przejęcie konta swoim
odnośnikiem właśnie wtedy, gdy właściciel zrobił dokładnie to, o co go
poprosiliśmy.

**Zmiana wymaga:** przemyślenia obu połówek naraz. Dopisanie `Rule::unique`
do formularza „dla wygody" przywraca wyciek; przeniesienie adresu na `users`
w chwili wysłania listu przywraca przejęcie konta na jedno kliknięcie.
Pilnują tego `ZmianaAdresuEmailTest` i `AdresEmailPozaMasowymPrzypisaniemTest`.

📄 `app/Domain/Users/Actions/RequestEmailChange.php` ·
`app/Domain/Users/Actions/ConfirmEmailChange.php` ·
`app/Domain/Users/Actions/CancelEmailChange.php` ·
`app/Models/PendingEmailChange.php` ·
`app/Http/Controllers/Settings/EmailSettingsController.php` ·
`docs/DATABASE.md` (`pending_email_changes`) ·
`docs/SECURITY_PRIVACY_LEGAL.md` (RODO art. 16)
