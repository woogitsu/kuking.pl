<?php

declare(strict_types=1);

namespace Tests\Dwa;

use RuntimeException;

/**
 * Jeden uczestnik wyścigu: osobny proces PHP, który wykonuje PRAWDZIWĄ akcję
 * domenową na własnym połączeniu do bazy wyścigów.
 *
 * DLACZEGO PROCES, A NIE DRUGIE POŁĄCZENIE W TYM SAMYM PHP.
 *
 * Bo w jednym procesie PHP nie da się zatrzymać `EraseAccountData::handle()`
 * w połowie i puścić w tym czasie drugiej egzekucji — kod jest synchroniczny.
 * Zostałoby przepisanie jego zapytań do testu i odegranie ich ręcznie na
 * dwóch połączeniach, czyli test mierzący SQL napisany w teście. Taki test
 * jest zielony także po zmianie kodu, którego pilnuje — a to jest definicja
 * atrapy z `AGENTS.md` §10.
 *
 * Osobny proces ma przy okazji własne połączenie z definicji, więc zasada 5
 * („naprawdę osobne połączenia") jest tu spełniona konstrukcyjnie, a nie
 * przez ustawienie, które da się przypadkiem zgubić.
 *
 * SYNCHRONIZACJA nie idzie przez czas, tylko przez blokady: zatrzymaniem
 * procesu w wybranym miejscu zajmuje się bariera z `TestDwochPolaczen`,
 * a tym, że proces NAPRAWDĘ już stoi w kolejce, zajmuje się
 * `czekajNaZablokowane()` — pytające `pg_stat_activity`, a nie `sleep`.
 */
final class ProcesRownolegly
{
    /** @var resource */
    private $uchwyt;

    /** @var array<int, resource> */
    private array $rury;

    private string $wyjscie = '';

    private string $bledy = '';

    /**
     * @param  resource  $uchwyt
     * @param  array<int, resource>  $rury
     */
    private function __construct($uchwyt, array $rury)
    {
        $this->uchwyt = $uchwyt;
        $this->rury = $rury;
    }

    /**
     * @param  array<string, string>  $argumenty
     * @param  array<string, string>  $srodowisko
     */
    public static function start(string $skrypt, string $scenariusz, array $argumenty, array $srodowisko): self
    {
        $polecenie = [PHP_BINARY, $skrypt, $scenariusz, (string) json_encode($argumenty)];

        $uchwyt = proc_open(
            $polecenie,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $rury,
            dirname($skrypt, 3),
            array_merge(getenv(), $srodowisko),
        );

        if (! is_resource($uchwyt)) {
            throw new RuntimeException('Nie udało się uruchomić procesu wyścigu: '.$scenariusz);
        }

        fclose($rury[0]);
        stream_set_blocking($rury[1], false);
        stream_set_blocking($rury[2], false);

        return new self($uchwyt, $rury);
    }

    /**
     * Czeka na koniec procesu i zwraca to, co zameldował.
     *
     * Twardy limit czasu jest tu z tego samego powodu, co `lock_timeout`
     * na połączeniach (zasada 4): proces, który utknął na blokadzie po
     * błędzie w teście, wiesza cały przebieg CI, a nie tylko siebie.
     *
     * @return array{ok: bool, sqlstate: ?string, komunikat: string, wartosc: mixed, wyjatek: ?string}
     */
    public function wynik(int $sekund = 60): array
    {
        $koniec = microtime(true) + $sekund;

        while (microtime(true) < $koniec) {
            $this->zbierz();

            $stan = proc_get_status($this->uchwyt);

            if ($stan['running'] === false) {
                $this->zbierz();

                return $this->rozbierz();
            }

            usleep(20_000);
        }

        $this->zabij();

        throw new RuntimeException(
            "Proces wyścigu nie skończył się w {$sekund} s. Zebrane wyjście:\n"
            .$this->wyjscie."\n".$this->bledy,
        );
    }

    private function zbierz(): void
    {
        foreach ([1, 2] as $numer) {
            if (! isset($this->rury[$numer]) || ! is_resource($this->rury[$numer])) {
                continue;
            }

            $kawalek = stream_get_contents($this->rury[$numer]);

            if ($kawalek === false || $kawalek === '') {
                continue;
            }

            if ($numer === 1) {
                $this->wyjscie .= $kawalek;
            } else {
                $this->bledy .= $kawalek;
            }
        }
    }

    /**
     * @return array{ok: bool, sqlstate: ?string, komunikat: string, wartosc: mixed, wyjatek: ?string}
     */
    private function rozbierz(): array
    {
        /** @var mixed $odczytane */
        $odczytane = json_decode($this->wyjscie, true);

        if (! is_array($odczytane) || ! array_key_exists('ok', $odczytane)) {
            throw new RuntimeException(
                "Proces wyścigu nie zameldował wyniku w JSON-ie. Wyjście:\n"
                .$this->wyjscie."\nBłędy:\n".$this->bledy,
            );
        }

        return [
            'ok' => (bool) $odczytane['ok'],
            'sqlstate' => isset($odczytane['sqlstate']) ? (string) $odczytane['sqlstate'] : null,
            'komunikat' => isset($odczytane['komunikat']) ? (string) $odczytane['komunikat'] : '',
            'wartosc' => $odczytane['wartosc'] ?? null,
            'wyjatek' => isset($odczytane['wyjatek']) ? (string) $odczytane['wyjatek'] : null,
        ];
    }

    public function zabij(): void
    {
        if (! is_resource($this->uchwyt)) {
            return;
        }

        $stan = proc_get_status($this->uchwyt);

        if ($stan['running'] === true) {
            proc_terminate($this->uchwyt, 9);
        }

        foreach ($this->rury as $rura) {
            if (is_resource($rura)) {
                fclose($rura);
            }
        }

        $this->rury = [];

        proc_close($this->uchwyt);
    }
}
