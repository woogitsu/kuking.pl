<?php

declare(strict_types=1);

namespace App\Domain\Kanaly;

/**
 * Klucze cache aplikacyjnego kanałów Atom — JEDNO miejsce, bo te same
 * klucze buduje kontroler (zapis) i `UniewaznijKanaly` (zapomnienie).
 *
 * Klucz PROFILU niesie znormalizowaną nazwę (`lower(trim())`, jak
 * wyszukiwanie w kontrolerze): treść kanału ma w sobie adresy `self`
 * i `alternate` z nazwy, więc po jej zmianie kopia pod starym kluczem
 * miałaby martwe adresy. Prostsze niż unieważnianie przy zmianie nazwy:
 * nowa nazwa to nowy klucz, a kopia pod starą jest nieosiągalna (stara
 * nazwa nie otwiera profilu) i wygasa sama po TTL.
 */
final class KluczeKanalu
{
    private const PREFIKS = 'kuking:kanal:v1:';

    public static function profil(string $kontoId, string $username): string
    {
        return self::PREFIKS.'profil:'.$kontoId.':'.mb_strtolower(trim($username));
    }

    public static function tag(string $tagId): string
    {
        return self::PREFIKS.'tag:'.$tagId;
    }

    public static function zeszyt(string $zeszytId): string
    {
        return self::PREFIKS.'zeszyt:'.$zeszytId;
    }
}
