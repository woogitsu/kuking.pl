<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

/**
 * Rozwiązywanie nazw przez resolver systemu (rekordy A i AAAA).
 *
 * Zwraca WSZYSTKIE adresy, nie pierwszy: strażnik odrzuca host, jeśli
 * choć jeden z jego adresów jest prywatny. Inaczej nazwa z dwoma rekordami
 * (publicznym i `10.0.0.5`) przechodziłaby kontrolę losowo, zależnie od
 * tego, który adres biblioteka HTTP wybrałaby przy łączeniu.
 */
final class SystemowyRozwiazywaczNazw implements RozwiazywaczNazw
{
    public function adresy(string $host): array
    {
        $adresy = [];

        $rekordy = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($rekordy)) {
            foreach ($rekordy as $rekord) {
                if (isset($rekord['ip']) && is_string($rekord['ip'])) {
                    $adresy[] = $rekord['ip'];
                }

                if (isset($rekord['ipv6']) && is_string($rekord['ipv6'])) {
                    $adresy[] = $rekord['ipv6'];
                }
            }
        }

        // Część środowisk (np. /etc/hosts) nie odpowiada na dns_get_record —
        // gethostbynamel czyta to, co widzi system przy łączeniu.
        if ($adresy === []) {
            $v4 = @gethostbynamel($host);

            if (is_array($v4)) {
                $adresy = $v4;
            }
        }

        return array_values(array_unique($adresy));
    }
}
