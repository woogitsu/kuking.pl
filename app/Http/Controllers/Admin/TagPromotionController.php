<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Tag;
use App\Models\TagHighlight;
use App\Models\TagPromotion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Panel gospodarza: tagi promowane (D-021, „tag promowany — lista
 * gospodarza", etap 5/5).
 *
 * PO CO TO ISTNIEJE
 * Tematy niosły trzy rzeczy, których otwarte tagi same nie niosą
 * (`docs/product/COLD_START.md`): gwarancję, że nowe konto nie widzi
 * pustki (onboarding + pierwszy feed), „temat tygodnia" ogłaszany przez
 * gospodarza i przygotowane tematy sezonowe (Wigilia, tłusty czwartek,
 * Wielkanoc) planowane z wyprzedzeniem. Ten ekran przywraca wszystkie
 * trzy — bez drugiego typu obiektu w interfejsie: promocja to zwykły tag,
 * który dodatkowo stoi na tej liście, z kolejnością i notatką.
 *
 * SEZONOWE OKAZJE NIE POTRZEBUJĄ OSOBNEGO MECHANIZMU. Gospodarz dodaje
 * tag „wigilia" do listy na kilka tygodni przed świętami, z notatką
 * „Temat tygodnia: co na wigilijny stół" — to jest ten sam formularz,
 * którego używa na co dzień, nie funkcja specjalna.
 *
 * TA SAMA BRAMKA CO „KUKINGI NA DZIŚ" (`DailyBoardController`) — `Gate`
 * `moderate` na `User`. To jest ten sam rodzaj decyzji redakcyjnej
 * gospodarza, nie osobny poziom uprawnień do wymyślania od nowa.
 * Middleware trasy (`moderator`, grupa `admin.*`) zwraca 404 zwykłemu
 * kontu, zanim ten kontroler w ogóle zostanie wywołany — panel moderacji
 * nie musi nikomu potwierdzać, że istnieje.
 *
 * „KTO I KIEDY" ZMIENIŁ LISTĘ ZAPISUJE `audit_log`, BEZ KOLUMNY
 * `promoted_by` NA `tag_promotions` — dokładnie ten sam wzorzec co
 * `daily_board.updated`. Uzasadnienie w komentarzu migracji
 * `2026_09_07_100100_create_tag_promotions_table`.
 *
 * OPIEKUN TAGU (nazwana osoba prowadząca temat, „ambasador" z
 * COLD_START.md) ŚWIADOMIE NIE WCHODZI DO TEGO EKRANU — to wymagałoby
 * dodatkowej kolumny (`curator_id`, jak przy `daily_picks.curator_id`)
 * i ekranu do przypisywania osób, a właściciel jeszcze tego nie zamówił.
 * Dołożenie tej kolumny później jest migracją dodającą nullable FK —
 * nie łamie niczego istniejącego (ten sam kształt rozszerzalności co
 * reszta modelu tagów, patrz `Tag::scopePromowane()`).
 */
class TagPromotionController extends Controller
{
    public function edit(Request $request): View
    {
        $this->authorize('moderate', User::class);

        return view('pages.admin.tag-promotions', [
            'promowane' => Tag::promowane()->get(),
            // Plan i archiwum tagu tygodnia (issue #18) — tylko przy włączonej fladze.
            'wyroznienia' => config('kuking.tag_tygodnia.wlaczony', false)
                ? TagHighlight::query()->with('tag')->orderByDesc('starts_on')->limit(20)->get()
                : null,
        ]);
    }

    /** Dodanie ISTNIEJĄCEGO tagu do listy — po nazwie, nie po id. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $dane = $request->validate([
            'nazwa_tagu' => ['required', 'string'],
        ], [
            'nazwa_tagu.required' => 'Wpisz nazwę tagu, który chcesz promować.',
        ]);

        $znormalizowana = Tag::znormalizujNazwe($dane['nazwa_tagu']);

        // TYLKO istniejący, aktywny tag — ten ekran nie tworzy nowych tagów.
        // Gospodarz promuje coś, co już żyje w serwisie; literówka w nazwie
        // ma dać komunikat „nie znaleziono", nie po cichu założyć nowy tag
        // o niechcianej pisowni.
        $tag = Tag::query()
            ->where('normalized_name', $znormalizowana)
            ->where('status', Tag::STATUS_ACTIVE)
            ->first();

        if ($tag === null) {
            return back()->withInput()->withErrors([
                'nazwa_tagu' => 'Nie znaleziono aktywnego tagu o tej nazwie. Sprawdź pisownię — tag musi już istnieć.',
            ]);
        }

        if ($tag->promotion !== null) {
            return back()->withInput()->withErrors(['nazwa_tagu' => 'Ten tag jest już promowany.']);
        }

        $nastepnaPozycja = ((int) TagPromotion::query()->max('position')) + 1;

        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => $nastepnaPozycja]);

        AuditLogEntry::record(
            action: 'tag_promotion.added',
            actor: $request->user(),
            subject: $tag,
            ip: $request->ip(),
        );

        return redirect()->route('admin.tag-promotions')->with('status', "Tag „{$tag->name}” dodany do promowanych.");
    }

    /** Notatka i kolejność — jedna promocja naraz. */
    public function update(Request $request, Tag $tag): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        $promocja = $tag->promotion;

        abort_if($promocja === null, 404);

        if ($request->has('w_gore') || $request->has('w_dol')) {
            $this->przesun($promocja, $request->has('w_gore') ? -1 : 1);

            AuditLogEntry::record(
                action: 'tag_promotion.reordered',
                actor: $request->user(),
                subject: $tag,
                ip: $request->ip(),
            );

            return back()->with('status', 'Kolejność zmieniona.');
        }

        $dane = $request->validate(['note' => ['nullable', 'string', 'max:200']]);

        $promocja->forceFill(['note' => $this->nullIfBlank($dane['note'] ?? null)])->save();

        AuditLogEntry::record(
            action: 'tag_promotion.note_updated',
            actor: $request->user(),
            subject: $tag,
            ip: $request->ip(),
        );

        return back()->with('status', 'Notatka zapisana.');
    }

    public function destroy(Request $request, Tag $tag): RedirectResponse
    {
        $this->authorize('moderate', User::class);

        // Usunięcie promocji NIE kasuje tagu — tag żyje dalej jako zwykły,
        // otwarty tag, dokładnie jak wycofanie Tematu nie kasowało wpisów.
        $tag->promotion?->delete();

        AuditLogEntry::record(
            action: 'tag_promotion.removed',
            actor: $request->user(),
            subject: $tag,
            ip: $request->ip(),
        );

        return redirect()->route('admin.tag-promotions')->with('status', "Tag „{$tag->name}” zdjęty z promowanych.");
    }

    /**
     * Zamienia pozycję z sąsiadem — prosta zamiana miejscami, bez
     * przenumerowywania całej listy. `$kierunek`: -1 w górę, +1 w dół.
     */
    private function przesun(TagPromotion $promocja, int $kierunek): void
    {
        $sasiad = TagPromotion::query()
            ->when(
                $kierunek < 0,
                fn ($q) => $q->where('position', '<', $promocja->position)->orderByDesc('position'),
                fn ($q) => $q->where('position', '>', $promocja->position)->orderBy('position'),
            )
            ->first();

        if ($sasiad === null) {
            // Już na końcu/początku listy — nie ma z kim zamienić.
            return;
        }

        DB::transaction(function () use ($promocja, $sasiad): void {
            $pozycjaPromocji = $promocja->position;
            $promocja->forceFill(['position' => $sasiad->position])->save();
            $sasiad->forceFill(['position' => $pozycjaPromocji])->save();
        });
    }

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
