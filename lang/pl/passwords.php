<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Odzyskiwanie hasła — komunikaty frameworka
|--------------------------------------------------------------------------
|
| PasswordResetController pisze dziś własne zdania i to jest dobrze: mówią
| konkretnie, co zrobić. Ten plik jest zabezpieczeniem na wypadek, gdyby
| ktoś kiedyś oddał komunikat frameworkowi (`Password::sendResetLink()`
| zwraca właśnie te klucze) — wtedy człowiek zobaczy polskie zdanie,
| a nie „We have emailed your password reset link!".
|
| Uwaga: `sent` i `user` MUSZĄ brzmieć tak samo w skutku, bo inaczej
| formularz odzyskiwania hasła zamienia się w narzędzie do sprawdzania,
| kto ma tu konto.
|
*/

return [
    'reset' => 'Hasło zmienione. Możesz się zalogować.',
    'sent' => 'Jeśli na ten adres jest założone konto, wysłaliśmy na niego wiadomość z linkiem do ustawienia nowego hasła. Sprawdź też folder „Spam”.',
    'throttled' => 'Poczekaj chwilę, zanim spróbujesz jeszcze raz.',
    'token' => 'Ten link do ustawienia hasła jest już nieaktualny. Poproś o nowy.',
    'user' => 'Jeśli na ten adres jest założone konto, wysłaliśmy na niego wiadomość z linkiem do ustawienia nowego hasła. Sprawdź też folder „Spam”.',
];
