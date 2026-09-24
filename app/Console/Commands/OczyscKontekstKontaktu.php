<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Contact\PageContext;
use App\Models\ContactMessage;
use Illuminate\Console\Command;

/**
 * Jednorazowe oczyszczenie `contact_messages.page_path` zapisanych przed
 * poprawką #836 — tych, w których ścieżka niesie token resetu hasła,
 * logowania linkiem, zaproszenia albo inny sekret z adresu.
 *
 * DOMYŚLNIE NICZEGO NIE ZMIENIA. Bez `--wykonaj` tylko liczy i pokazuje,
 * ile wierszy zmieniłoby się na który ekran. Uruchamia właściciel, po
 * obejrzeniu podglądu. Wartości kolumny NIE są wypisywane — to byłoby
 * przeniesienie tokenu z bazy do terminala i historii poleceń.
 *
 * Wiadomości nie kasuje i nie rusza jej treści ani `updated_at`: podmienia
 * wyłącznie `page_path` na nazwę ekranu (`/nowe-haslo`) albo na `NULL`,
 * gdy ścieżki nie da się jednoznacznie odczytać (np. kodowanie `%`,
 * które mogło ukryć sekret). Inne różnice po `PageContext::clean()` są
 * tylko liczone — to nie jest zadanie tej komendy.
 */
class OczyscKontekstKontaktu extends Command
{
    protected $signature = 'kuking:oczysc-kontekst-kontaktu
                            {--wykonaj : Naprawdę zapisz zmiany (bez tej flagi tylko podgląd)}';

    protected $description = 'Usuwa tokeny z zapisanego kontekstu strony w wiadomościach „Napisz do nas" (#836). Domyślnie tylko podgląd.';

    public function handle(): int
    {
        $wykonaj = (bool) $this->option('wykonaj');
        $ekrany = PageContext::maskedScreens();

        /** @var array<string, int> $naEkran */
        $naEkran = [];
        $pominiete = 0;

        ContactMessage::query()
            ->whereNotNull('page_path')
            ->select(['id', 'page_path'])
            ->chunkById(500, function ($wiersze) use ($wykonaj, $ekrany, &$naEkran, &$pominiete): void {
                foreach ($wiersze as $wiersz) {
                    $czysta = PageContext::clean($wiersz->page_path);

                    if ($czysta === $wiersz->page_path) {
                        continue;
                    }
                    if ($czysta !== null && ! in_array($czysta, $ekrany, true)) {
                        $pominiete++;

                        continue;
                    }

                    $klucz = $czysta ?? '(puste — ścieżka niejednoznaczna)';
                    $naEkran[$klucz] = ($naEkran[$klucz] ?? 0) + 1;

                    if ($wykonaj) {
                        // `toBase()`: bez dotykania `updated_at` — to nie jest
                        // zmiana sprawy, tylko usunięcie sekretu.
                        ContactMessage::query()->whereKey($wiersz->getKey())->toBase()->update(['page_path' => $czysta]);
                    }
                }
            });

        $razem = array_sum($naEkran);
        $this->info($wykonaj ? 'Tryb zapisu.' : 'Podgląd — niczego nie zmieniam. Aby zapisać, dodaj --wykonaj.');

        if ($razem === 0) {
            $this->line('Żadna zapisana ścieżka nie niesie sekretu. Nie ma czego poprawiać.');
        } else {
            $this->table(['Nowa wartość', 'Wiadomości'], collect($naEkran)->map(fn (int $n, string $k): array => [$k, $n])->values()->all());
            $this->line(($wykonaj ? 'Poprawiono' : 'Do poprawienia').": {$razem}.");
        }

        if ($pominiete > 0) {
            $this->line("Pozostałe różnice (bez sekretu, nie ruszam): {$pominiete}.");
        }

        return self::SUCCESS;
    }
}
