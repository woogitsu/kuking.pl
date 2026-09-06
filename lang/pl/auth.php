<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Logowanie — komunikaty frameworka
|--------------------------------------------------------------------------
|
| LoginController ma własne, konkretniejsze zdania i one mają pierwszeństwo.
| Ten plik pilnuje, żeby ścieżka przez frameworkowy `auth` (np. potwierdzenie
| hasła przed operacją wrażliwą) nie odezwała się po angielsku.
|
| Zdania mówią, CO ZROBIĆ — „These credentials do not match our records"
| nie mówi nic (docs/UX_50_PLUS.md).
|
*/

return [
    'failed' => 'Nie udało się zalogować. Sprawdź, czy nazwa i hasło są wpisane poprawnie. Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła”.',
    'password' => 'To hasło nie pasuje do konta. Spróbuj jeszcze raz.',
    'throttle' => 'Za dużo prób logowania. Spróbuj ponownie za :seconds s.',
];
