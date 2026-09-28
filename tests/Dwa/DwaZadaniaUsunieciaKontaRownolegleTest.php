<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\PotwierdzenieZadaniaRodo;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * DWA FORMULARZE „USUŃ KONTO" RÓWNOCZEŚNIE, NA DWÓCH POŁĄCZENIACH (#1346).
 *
 * Dwie karty z RÓŻNYM zakresem (`minimum` i `everything`). Obaj uczestnicy
 * czytają konto (`active`) ZANIM staną w kolejce po wiersz — bariera trzyma
 * `FOR UPDATE` na `users`. Wynik: jedno przyjęte żądanie, jedna sprawa
 * `w_toku`, jeden wpis `account.delete_requested`; drugi dostaje komunikat
 * „już oznaczone" i nie nadpisuje zakresu ani daty pierwszego.
 *
 * ── KONTROLA UJEMNA (wykonana) ──
 * `User::przejdz()` przekazujące do zmiany `$this` zamiast świeżego wiersza
 * spod blokady (stan sprzed #980): drugi uczestnik nie dostaje
 * `BladDlaCzlowieka`, tylko odbija się od indeksu
 * `potwierdzenia_zadan_rodo_jedna_w_toku_na_konto` (23505) — a bez tego
 * indeksu przechodzi i zakłada drugą sprawę `w_toku`.
 */
#[Group('dwa-polaczenia')]
final class DwaZadaniaUsunieciaKontaRownolegleTest extends TestDwochPolaczen
{
    public function test_dwa_rownolegle_zadania_daja_jedna_sprawe_i_zakres_pierwszego(): void
    {
        $konto = $this->konto();
        $id = (string) $konto->getKey();

        $bariera = $this->bariera('SELECT id FROM users WHERE id = ? FOR UPDATE', [$id]);
        $a = $this->wTle('przyjmij-usuniecie', ['konto' => $id, 'zakres' => User::DELETE_SCOPE_MINIMUM]);
        $this->czekajNaZablokowane(1);
        $b = $this->wTle('przyjmij-usuniecie', ['konto' => $id, 'zakres' => User::DELETE_SCOPE_EVERYTHING]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = ['minimum' => $a->wynik(), 'everything' => $b->wynik()];

        foreach ($wyniki as $wynik) {
            $this->assertBezZakleszczenia($wynik, 'dwa żądania usunięcia');
        }

        $udane = array_keys(array_filter($wyniki, static fn (array $w): bool => $w['ok']));
        $this->assertCount(1, $udane, 'Przyjęte mają być dokładnie jedno żądanie: '.json_encode($wyniki, JSON_UNESCAPED_UNICODE));

        $zwyciezca = $udane[0];
        $przegrany = $wyniki[$zwyciezca === 'minimum' ? 'everything' : 'minimum'];
        $this->assertSame(BladDlaCzlowieka::class, $przegrany['wyjatek'], $przegrany['komunikat']);
        $this->assertStringContainsString('już oznaczone do usunięcia', $przegrany['komunikat']);

        $stan = $konto->fresh();
        $this->assertSame(User::STATUS_PENDING_DELETE, $stan->status);
        $this->assertSame($zwyciezca, $stan->delete_scope, 'Drugie żądanie nadpisało zakres pierwszego.');

        $this->assertSame(1, PotwierdzenieZadaniaRodo::query()
            ->where('konto_id', $id)->where('wynik', PotwierdzenieZadaniaRodo::WYNIK_W_TOKU)->count());

        $wpisy = AuditLogEntry::query()->where('action', 'account.delete_requested')->where('subject_id', $id)->get();
        $this->assertCount(1, $wpisy);
        $this->assertSame(['zakres' => $zwyciezca], $wpisy->sole()->metadata);
    }
}
