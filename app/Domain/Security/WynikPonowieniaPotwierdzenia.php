<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Co się stało po kliknięciu „Wyślij wiadomość jeszcze raz" (D-246).
 *
 * TRZY WYNIKI, NIE `bool`, bo człowiek po drugiej stronie ma przy każdym
 * z nich zrobić coś innego:
 *
 *  - `Wyslano` — sprawdź skrzynkę;
 *  - `SufitKonta` — TO konto dostało już dziś tyle listów, ile wysyłamy
 *    jednej osobie; kolejny można zamówić jutro, a pula serwisu ma się
 *    dobrze;
 *  - `BrakMiejscaWPuli` — skończyła się część dobowej puli, którą wolno
 *    przeznaczyć na ponowienia (albo prośba trafiła na ścisk na blokadzie
 *    licznika — to rozróżnia kontroler odczytem puli, tak jak przy logowaniu
 *    linkiem).
 *
 * Gdyby to był `bool`, pierwsze „nie" wyglądałoby jak drugie i ekran
 * musiałby zgadywać, czy mówić o koncie, czy o całym serwisie.
 */
enum WynikPonowieniaPotwierdzenia
{
    case Wyslano;
    case SufitKonta;
    case BrakMiejscaWPuli;
}
