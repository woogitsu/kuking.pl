<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ContactMessage;
use App\Models\User;

/**
 * Kto może czytać i obsługiwać wiadomości z „Napisz do nas".
 *
 * DLACZEGO POLITYKA, SKORO TRASY STOJĄ ZA `moderator` I `moderator.2fa`
 * Bo UUID W ADRESIE NIE JEST AUTORYZACJĄ (AGENTS.md §7), a middleware
 * pilnuje WEJŚCIA DO PANELU, nie prawa do konkretnego wiersza. Te dwie
 * rzeczy rozjeżdżają się w chwili, w której ktoś doda drugą drogę do tego
 * samego modelu — link z powiadomienia, endpoint podglądu, cokolwiek —
 * i zapomni powtórzyć całą trójkę middleware'ów. Polityka jedzie razem
 * z modelem i nie da się jej ominąć, dopisując trasę.
 *
 * W tej tabeli stawka jest realna: wiadomość „nie mogę się zalogować,
 * mój adres to …" niesie dane osobowe osoby, która nie ma nawet konta.
 *
 * ZASIĘG: moderator i administrator, czyli ci sami ludzie, którzy prowadzą
 * kolejkę zgłoszeń (D-012 — zespół to jedna, najwyżej dwie osoby). Autor
 * wiadomości NIE dostaje tu wglądu i to jest świadome: nie ma ekranu
 * „moje wiadomości" ani niczego, co by go potrzebowało, a każde prawo
 * nadane „na zapas" trzeba potem pilnować.
 */
class ContactMessagePolicy
{
    /** Kolejka wiadomości w panelu. */
    public function viewAny(User $user): bool
    {
        return $user->isModerator();
    }

    /** Podgląd jednej wiadomości. */
    public function view(User $user, ContactMessage $wiadomosc): bool
    {
        return $user->isModerator();
    }

    /** Zmiana stanu obsługi i notatka wewnętrzna. */
    public function handle(User $user, ContactMessage $wiadomosc): bool
    {
        return $user->isModerator();
    }

    /**
     * Wysłanie ODPOWIEDZI POCZTĄ do osoby, która napisała (D-058).
     *
     * OSOBNA ZDOLNOŚĆ, NIE `handle()`, mimo że dziś odpowiada na oba
     * pytania tak samo. Powód jest w tym, czym te dwie rzeczy są:
     * `handle()` zmienia NASZĄ notatkę i NASZ stan kolejki — skutek nie
     * wychodzi poza panel. Ta zdolność wypuszcza list na adres e-mail
     * człowieka, którego nie da się już odwołać. Zrównanie ich znaczyłoby,
     * że dnia, w którym powstanie rola „stażysta moderacji" z prawem do
     * porządkowania kolejki, dostanie ona razem z nią prawo do pisania
     * z adresu `kontakt@kuking.pl` do ludzi z zewnątrz — i nikt by tego
     * nie zauważył, bo to byłaby jedna zmiana w jednej metodzie.
     *
     * Sam zakres jest na razie ten sam: moderator i administrator (D-012 —
     * zespół to jedna, najwyżej dwie osoby). Rozdzielone jest MIEJSCE,
     * w którym ta decyzja się zapisuje.
     */
    public function reply(User $user, ContactMessage $wiadomosc): bool
    {
        return $user->isModerator();
    }
}
