<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD = ['photo_upload_failed', 'search_performed', 'weekly_digest_queued', 'weekly_digest_unsubscribed'];

    private const PWA = ['pwa_prompt_shown', 'pwa_install_requested', 'pwa_prompt_dismissed', 'pwa_installed'];

    public function up(): void
    {
        $this->replaceCheck([...self::OLD, ...self::PWA]);
    }

    public function down(): void
    {
        // Usuwamy wyłącznie telemetrię z retencją 90 dni. Decyzja konta
        // pozostaje w users.pwa_prompt_state i ma osobną ochronę rollbacku.
        DB::transaction(function (): void {
            DB::table('product_signals')->whereIn('signal_name', self::PWA)->delete();
            $this->replaceCheck(self::OLD);
        });
    }

    private function replaceCheck(array $names): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $values = implode(', ', array_map(static fn (string $name): string => "'".$name."'", $names));
        DB::statement('ALTER TABLE product_signals DROP CONSTRAINT product_signals_signal_name_check');
        DB::statement('ALTER TABLE product_signals ADD CONSTRAINT product_signals_signal_name_check CHECK (signal_name IN ('.$values.'))');
    }
};
