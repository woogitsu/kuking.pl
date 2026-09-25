<?php

declare(strict_types=1);

namespace App\Domain\Security\WejsciePrzezDostawce;

use App\Models\User;

/**
 * To, czym dostawcy RÓŻNIĄ SIĘ w regułach Kuking (issue #1035).
 *
 * Implementacje: `App\Google\DostawcaWejsciaGoogle`
 * i `App\Facebook\DostawcaWejsciaFacebook`. Każda metoda tutaj to świadoma
 * różnica, nie wygoda — dopisanie metody znaczy „ta reguła nie jest wspólna".
 * Wszystko, co jest wspólne, mieszka w `WejdzPrzezDostawce` i nie ma tu
 * swojego przełącznika.
 */
interface DostawcaWejscia
{
    /**
     * `google` albo `facebook` — to samo co `TozsamoscZewnetrzna::DOSTAWCA_*`.
     * Z tej nazwy biorą się klucze sesji (`wejscie_<nazwa>.*`), klucz szkicu
     * domknięcia i `droga` w dzienniku zakładania konta.
     */
    public function nazwa(): string;

    /**
     * Nazwa pola identyfikatora w sesji (`sub` przy Google, `identyfikator`
     * przy Facebooku). Zostaje po staremu, żeby wdrożenie nie unieważniło
     * rozpoczętych domknięć.
     */
    public function poleIdentyfikatoraWSesji(): string;

    /** Ile minut tożsamość może leżeć w sesji, zanim trzeba zacząć od nowa. */
    public function waznoscDomknieciaMinut(): int;

    /**
     * Czy dostawca dowodzi kontroli nad skrzynką. Google — tak
     * (`email_verified`), Facebook — nie (D-098). Od tego zależy, czy nowe
     * konto powstaje z adresem potwierdzonym.
     */
    public function potwierdzaAdres(): bool;

    public function kontoPowiazane(string $identyfikator): ?User;

    public function maPolaczenie(User $user): bool;

    /** Zapis powiązania. Woła go tylko `WejdzPrzezDostawce::polacz()`, pod blokadą konta. */
    public function polacz(User $user, string $identyfikator): void;

    /**
     * Czym człowiek DOWIÓDŁ, że to konto jest jego — dokładane do wspólnych
     * warunków łączenia.
     *
     * Google: adres od Google zgadza się z adresem konta i jest u nas
     * potwierdzony (reguły 2 i 3 D-069). Facebook: nic więcej — dowodem jest
     * to, że człowiek JEST ZALOGOWANY na to konto (D-098, punkt 4); adres
     * z Facebooka nie dowodzi niczego.
     */
    public function dowodPolaczenia(User $user, TozsamoscOdDostawcy $tozsamosc): bool;

    /**
     * Skutek wejścia właściwy dostawcy, po bramkach i przed zapisem
     * w dzienniku. Facebook gasi tu znacznik odebrania dostępu (#259).
     */
    public function przedWejsciem(User $user): void;

    /** Akcja w dzienniku audytu przy wejściu na konto. */
    public function akcjaWejscia(): string;

    /** Akcja w dzienniku audytu przy połączeniu konta. */
    public function akcjaPolaczenia(): string;

    /**
     * Nazwany argument `ZalozKonto::handle()` niosący powiązanie, np.
     * `['googleSub' => $identyfikator]`.
     *
     * @return array<string, string>
     */
    public function powiazanieNowegoKonta(string $identyfikator): array;
}
