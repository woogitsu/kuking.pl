<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Prawdziwy dysk lokalny dla testów eksportu danych, z punktami
 * zaczepienia na wyścig przy wymazaniu konta (issue #1307) i na awarie
 * magazynu (issue #1388).
 *
 *  - `$poZapisie` — wołane ZARAZ PO `writeStream()` gotowej paczki, czyli
 *    dokładnie w oknie między zapisem obiektu a przejściem w `ready`.
 *    Tu test wymazuje konto, zamiast liczyć na szczęśliwy `sleep`.
 *  - `$odmowUsuniecia` — `delete()` nie usuwa niczego i zwraca `false`,
 *    jak dysk z `throw => false` przy awarii magazynu.
 *  - `$odmowOdczytu` — klucz → `'false'`, `'wyjatek'` albo `'uciety'`:
 *    `readStream()` tego klucza oddaje `false` (dysk z `throw => false`),
 *    rzuca, choć plik leży na dysku (issue #1388, zdjęcie `ready` nie do
 *    odczytania), albo oddaje tylko połowę pliku, a `size()` całość — jak
 *    połączenie zerwane w trakcie pobierania.
 *
 * Reszta zachowania to zwykły `FilesystemAdapter` na katalogu w
 * `storage/framework/testing/disks/`, tak jak przy `Storage::fake()`.
 */
final class DyskEksportuZHakiem extends FilesystemAdapter
{
    public ?Closure $poZapisie = null;

    public bool $odmowUsuniecia = false;

    /** @var array<string, 'false'|'wyjatek'|'uciety'> */
    public array $odmowOdczytu = [];

    public static function zarejestruj(string $nazwa): self
    {
        $root = storage_path('framework/testing/disks/'.$nazwa);

        (new \Illuminate\Filesystem\Filesystem)->cleanDirectory($root);

        $adapter = new LocalFilesystemAdapter($root);
        $dysk = new self(new Filesystem($adapter), $adapter, ['root' => $root]);

        Storage::set($nazwa, $dysk);
        config(["filesystems.disks.{$nazwa}" => ['driver' => 'local', 'root' => $root]]);

        return $dysk;
    }

    public function writeStream($path, $resource, array $options = [])
    {
        $wynik = parent::writeStream($path, $resource, $options);

        if ($this->poZapisie !== null) {
            $hak = $this->poZapisie;
            $this->poZapisie = null;
            $hak($path);
        }

        return $wynik;
    }

    public function readStream($path)
    {
        return match ($this->odmowOdczytu[$path] ?? null) {
            'false' => false,
            'wyjatek' => throw new \RuntimeException('Udawana awaria magazynu: /sciezka/do/'.$path),
            'uciety' => $this->polowa((string) $this->get($path)),
            default => parent::readStream($path),
        };
    }

    /** @return resource */
    private function polowa(string $tresc)
    {
        $strumien = fopen('php://memory', 'w+b');
        fwrite($strumien, substr($tresc, 0, intdiv(strlen($tresc), 2)));
        rewind($strumien);

        return $strumien;
    }

    public function delete($paths)
    {
        return $this->odmowUsuniecia ? false : parent::delete($paths);
    }
}
