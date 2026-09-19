// Szybki podgląd jest ulepszeniem zwykłego formularza POST z CSRF.
let cleanup = () => {};
function initialize() {
    cleanup();
    const widget = document.querySelector('[data-szybki-wyglad]');
    if (!widget) return;
    const form = widget.querySelector('form');
    const scale = form.elements.text_scale;
    const theme = form.elements.theme;
    const status = widget.querySelector('[data-wyglad-status]');
    const summary = widget.querySelector('summary');
    const hint = document.querySelector('[data-wyglad-podpowiedz]');
    const events = new AbortController();
    const listen = (target, name, fn) => target.addEventListener(name, fn, {signal: events.signal});
    let saved = {text_scale: scale.value, theme: theme.value};
    let completion = Promise.resolve();
    let pending = null;
    let running = false;
    let revision = 0;
    let pointerOnFlowSummary = false;
    const apply = (choice) => {
        document.documentElement.dataset.textScale = String(choice.text_scale);
        document.documentElement.dataset.theme = choice.theme;
        const footer = document.querySelector('.site-footer-motyw');
        if (footer) {
            footer.elements.theme.value = choice.theme === 'dark' ? 'light' : 'dark';
            footer.querySelector('button').textContent = choice.theme === 'dark' ? 'Włącz jasny wygląd' : 'Włącz ciemny wygląd';
            const description = footer.querySelector('.visually-hidden');
            if (description) description.textContent = 'Wygląd strony: ' + (choice.theme === 'dark' ? 'ciemny.' : 'jasny.');
        }
    };
    const forgetHint = () => {
        hint.hidden = true;
        try { localStorage.setItem('kuking-wyglad-poznany', '1'); } catch { /* Brak pamięci nie blokuje ustawień. */ }
        // Podpowiedź była najszerszą z pływających warstw — po jej zniknięciu
        // widget zwykle może wrócić do pływania (issue #684).
        zaplanujPomiar();
    };
    const geometry = () => {
        if (pointerOnFlowSummary) return;
        const wasInFlow = widget.hasAttribute('data-wyglad-w-przeplywie');
        if (wasInFlow) widget.removeAttribute('data-wyglad-w-przeplywie');
        const nav = document.querySelector('.bottom-nav');
        const rect = nav?.getBoundingClientRect();
        // Przy dużym piśmie nawigacja przewija się ze stroną. Nadal może
        // zasłonić przycisk: liczy się jej widoczny prostokąt, nie position.
        const margin = parseFloat(getComputedStyle(widget).right) || 0;
        const summaryHeight = summary.getBoundingClientRect().height;
        const visible = rect && rect.height > 0 && rect.bottom > innerHeight - summaryHeight - margin && rect.top < innerHeight;
        const limit = Math.max(0, innerHeight - summaryHeight - margin - 4);
        const bottom = visible ? Math.min(limit, Math.max(0, innerHeight - rect.top)) : 0;
        document.documentElement.style.setProperty('--wyglad-dol', bottom + 'px');
        // Sprawdzamy także rzeczywisty panel: duża czcionka może zajmować
        // więcej niż stała rezerwa arkusza nawet przy 240 px nad przyciskiem.
        widget.removeAttribute('data-wyglad-malo-miejsca');
        const panelOutside = widget.open && widget.querySelector('.szybki-wyglad-panel').getBoundingClientRect().top < 4;
        widget.toggleAttribute('data-wyglad-malo-miejsca', summary.getBoundingClientRect().top < 240 || panelOutside);
        // Komunikat błędu musi być czytelny także podczas przewijania myszą,
        // gdy fokus pozostaje w polu, a nie w samym komunikacie.
        if (!widget.open) {
            const floating = summary.getBoundingClientRect();
            const obscuresError = [...document.querySelectorAll('.field-error')].some(error =>
                [...error.getClientRects()].some(r => r.width > 0 && r.height > 0
                    && r.right > floating.left && r.left < floating.right
                    && r.bottom > floating.top && r.top < floating.bottom));
            if (obscuresError) {
                widget.setAttribute('data-wyglad-w-przeplywie', '');
                return;
            }
        }
        if (wasInFlow && document.activeElement && !document.activeElement.matches('body, html') && !widget.contains(document.activeElement)) {
            const target = document.activeElement;
            const floating = summary.getBoundingClientRect();
            if ([...target.getClientRects()].some(r => r.right + 8 > floating.left && r.left - 8 < floating.right && r.bottom + 8 > floating.top && r.top - 8 < floating.bottom)) widget.setAttribute('data-wyglad-w-przeplywie', '');
        }
        // Wszystko wyżej pilnuje FOKUSU, czyli klawiatury. Mysz i dotyk nie
        // dają żadnego zdarzenia, w którym dałoby się to zauważyć — a przykryta
        // kontrolka jest dla nich tak samo nieosiągalna (issue #684). Sam
        // pomiar jest niżej i chodzi rzadziej niż ta funkcja; tutaj tylko
        // czytamy jego ostatni wynik.
        if (zaslaniaWskaznikowi) widget.setAttribute('data-wyglad-w-przeplywie', '');
    };
    // ────────────────────────────────────────────────────────────────────
    //  CZY PŁYWAJĄCA WARSTWA ODBIERA DOSTĘP — issue #684
    // ────────────────────────────────────────────────────────────────────
    //
    // Pytanie brzmi „czy odbiera dostęp", a NIE „czy na coś zachodzi".
    // Zachodzi z definicji: przycisk jest `position: fixed`, więc przy
    // przewijaniu przechodzi nad każdym fragmentem strony po kolei — to jest
    // zamierzone i samo w sobie nikomu nie przeszkadza. Dostęp znika dopiero
    // wtedy, gdy kontrolka jest przykryta W CAŁOŚCI: nie zostaje wtedy ani
    // jeden piksel, w który da się kliknąć albo trafić palcem.
    //
    // Sprawdzamy dziewięć punktów NA KONTROLCE (rogi, środki boków, środek) —
    // tak samo, jak mierzy to `scripts/wyglad-nie-zaslania.mjs`. Pierwsza
    // wersja tej poprawki próbkowała punkty na WARSTWIE i przepuszczała
    // wszystko, co się między nie zmieściło: odnośnik stopki „Regulamin"
    // ma 78 × 20 px, a podpowiedź 296 × 227 px, więc dziewięć punktów po
    // warstwie mijało go bez trudu. Pomiar spadł wtedy z 31 naruszeń na 25
    // i to była jedyna różnica, jaką zrobił.
    //
    // DLACZEGO PRZEGLĄD WSZYSTKICH KONTROLEK, A NIE `elementsFromPoint`
    // Bo tylko on odpowiada na to pytanie pewnie. Cenę płacimy nie
    // oszczędzaniem na dokładności, tylko CZĘSTOTLIWOŚCIĄ: pomiar chodzi po
    // uspokojeniu się przewijania (`ODSTEP_POMIARU`), a nie przy każdym
    // zdarzeniu `scroll`. Podczas samego przesuwania palcem nie liczymy nic.
    const KONTROLKI = 'a[href], button, summary, select, input:not([type="hidden"]), textarea, [role="button"]';
    const ODSTEP_POMIARU = 150;
    let zaslaniaWskaznikowi = false;
    let pomiarZaplanowany = null;
    const wWarstwie = (x, y, w) => x >= w.left && x <= w.right && y >= w.top && y <= w.bottom;
    const odbieraDostep = () => {
        const warstwy = [summary.getBoundingClientRect()];
        // `!== 'static'` zamiast `=== 'fixed'`: po zejściu do przepływu arkusz
        // stawia podpowiedź na `static` i wtedy nie leży już nad niczym.
        if (hint && !hint.hidden && getComputedStyle(hint).position !== 'static') warstwy.push(hint.getBoundingClientRect());

        const nad = warstwy.filter(w => w.width > 0 && w.height > 0);
        if (nad.length === 0) return false;

        for (const el of document.querySelectorAll(KONTROLKI)) {
            if (widget.contains(el) || (hint && hint.contains(el))) continue;
            const r = el.getBoundingClientRect();
            if (r.width <= 0 || r.height <= 0) continue;
            if (r.bottom <= 0 || r.top >= innerHeight || r.right <= 0 || r.left >= innerWidth) continue;
            // Tanie odsianie: nie dotyka żadnej warstwy, więc nie ma sprawy.
            if (!nad.some(w => r.right > w.left && r.left < w.right && r.bottom > w.top && r.top < w.bottom)) continue;

            let wolny = false;
            for (const x of [r.left + 2, r.left + r.width / 2, r.right - 2]) {
                for (const y of [r.top + 2, r.top + r.height / 2, r.bottom - 2]) {
                    // Punkt poza oknem też jest nie do trafienia — nie liczy się jako wolny.
                    if (x < 0 || y < 0 || x >= innerWidth || y >= innerHeight) continue;
                    if (!nad.some(w => wWarstwie(x, y, w))) { wolny = true; break; }
                }
                if (wolny) break;
            }

            if (!wolny) return true;
        }

        return false;
    };
    // Pomiar MUSI widzieć geometrię pływającą. Gdyby liczył przy widgecie już
    // zepchniętym do przepływu, nie zobaczyłby żadnej warstwy nad stroną,
    // orzekłby „nie zasłania", widget wróciłby do pływania i zasłonił znowu —
    // i tak w kółko przy każdym przewinięciu.
    const zmierzZaslanianie = () => {
        pomiarZaplanowany = null;
        const wPrzeplywie = widget.hasAttribute('data-wyglad-w-przeplywie');
        if (wPrzeplywie) widget.removeAttribute('data-wyglad-w-przeplywie');
        const teraz = odbieraDostep();
        if (wPrzeplywie) widget.setAttribute('data-wyglad-w-przeplywie', '');
        if (teraz === zaslaniaWskaznikowi) return;
        zaslaniaWskaznikowi = teraz;
        if (!teraz) widget.removeAttribute('data-wyglad-w-przeplywie');
        geometry();
    };
    const zaplanujPomiar = () => {
        if (pomiarZaplanowany !== null) clearTimeout(pomiarZaplanowany);
        pomiarZaplanowany = setTimeout(zmierzZaslanianie, ODSTEP_POMIARU);
    };
    const save = async () => {
        if (running || !pending) return;
        const item = pending;
        pending = null;
        running = true;
        const data = new FormData(form);
        data.set('theme', item.choice.theme);
        data.set('text_scale', item.choice.text_scale);
        const focused = document.activeElement;
        const controls = [...form.querySelectorAll('button:not([data-wyglad-zamknij]), select')];
        controls.forEach(control => { control.disabled = true; });
        try {
            const response = await fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin', keepalive: true, signal: AbortSignal.timeout(10000), headers: {Accept: 'application/json'}});
            if (!response.ok) throw new Error('Zapis odrzucony');
            const result = await response.json();
            saved = {theme: result.theme, text_scale: String(result.text_scale)};
            if (widget.isConnected && item.revision === revision) status.textContent = 'Wygląd zapisany.';
        } catch {
            if (widget.isConnected && item.revision === revision) {
                scale.value = saved.text_scale;
                theme.value = saved.theme;
                apply(saved);
                status.textContent = 'Nie udało się zapisać wyglądu. Przywróciliśmy ostatnie ustawienie. Spróbuj ponownie.';
            }
        } finally {
            running = false;
            controls.forEach(control => { control.disabled = false; });
            if (widget.open && document.activeElement === document.body && form.contains(focused)) focused.focus({preventScroll: true});
        }
    };
    const change = () => {
        if (running) return;
        const choice = {theme: theme.value, text_scale: scale.value};
        revision++;
        apply(choice);
        geometry();
        forgetHint();
        status.textContent = 'Zapisujemy wygląd…';
        pending = {choice, revision};
        completion = save();
    };
    // Przejście linkiem czeka na rozpoczęty zapis, zamiast gubić wybór.
    const navigate = event => {
        const link = event.target.closest('a[href]');
        if (!running || !link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download') || !link.href.startsWith(location.origin)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        completion.then(() => { location.href = link.href; });
    };
    document.addEventListener('click', navigate, {capture: true, signal: events.signal});
    listen(form, 'change', change);
    listen(form, 'submit', event => {
        event.preventDefault();
        if (event.submitter?.name === 'reset_appearance') { scale.value = '100'; theme.value = 'light'; }
        change();
    });
    widget.querySelectorAll('[data-skala-krok]').forEach(button => {
        button.hidden = false;
        listen(button, 'click', () => {
            const next = Math.max(0, Math.min(scale.options.length - 1, scale.selectedIndex + Number(button.dataset.skalaKrok)));
            if (next !== scale.selectedIndex) { scale.selectedIndex = next; change(); }
        });
    });
    const close = () => { widget.open = false; summary.focus(); };
    const closeButton = widget.querySelector('[data-wyglad-zamknij]');
    closeButton.hidden = false;
    listen(closeButton, 'click', close);
    listen(summary, 'pointerdown', () => { pointerOnFlowSummary = widget.hasAttribute('data-wyglad-w-przeplywie'); });
    listen(summary, 'pointercancel', () => { pointerOnFlowSummary = false; });
    listen(document, 'pointerup', () => {
        if (pointerOnFlowSummary) setTimeout(() => {
            if (!widget.isConnected) return;
            pointerOnFlowSummary = false;
            geometry();
        }, 0);
    });
    listen(summary, 'click', () => {
        pointerOnFlowSummary = false;
        widget.removeAttribute('data-wyglad-w-przeplywie');
        geometry();
    });
    listen(widget, 'keydown', event => { if (event.key === 'Escape') { event.preventDefault(); close(); } });
    listen(widget, 'toggle', () => { if (widget.open) { forgetHint(); geometry(); } });
    listen(document, 'pointerdown', event => { if (!widget.contains(event.target)) widget.open = false; });
    listen(document, 'focusin', event => {
        // Tab przewija stronę, zanim dotrze zdarzenie scroll. Aktualizujemy
        // położenie również dla fokusu wewnątrz samego przełącznika.
        geometry();
        if (widget.contains(event.target)) {
            if (pointerOnFlowSummary) return;
            widget.removeAttribute('data-wyglad-w-przeplywie');
            geometry();
            return;
        }
        if (hint.contains(event.target)) return;
        widget.open = false;
        hint.hidden = true;
        requestAnimationFrame(() => {
            if (document.activeElement !== event.target) return;
            widget.removeAttribute('data-wyglad-w-przeplywie');
            geometry();
            const fragments = [...event.target.getClientRects()];
            const css = getComputedStyle(event.target);
            const ring = Math.max(8, (parseFloat(css.outlineWidth) || 0) + (parseFloat(css.outlineOffset) || 0));
            const floating = summary.getBoundingClientRect();
            const collisions = fragments.filter(r => r.right + ring > floating.left && r.left - ring < floating.right && r.bottom + ring > floating.top && r.top - ring < floating.bottom);
            if (!collisions.length) return;
            const shift = Math.max(...collisions.map(r => r.bottom + ring - floating.top)) + 16;
            const header = document.querySelector('.topbar');
            const headerStyle = header && getComputedStyle(header);
            const top = headerStyle && ['fixed', 'sticky'].includes(headerStyle.position) ? Math.max(0, header.getBoundingClientRect().bottom) : 0;
            if (fragments.every(r => r.top - ring - shift > top)) {
                window.scrollBy(0, shift);
                // Koniec dokumentu może ograniczyć faktyczne przesunięcie.
                const after = summary.getBoundingClientRect();
                if ([...event.target.getClientRects()].some(r => r.right + ring > after.left && r.left - ring < after.right && r.bottom + ring > after.top && r.top - ring < after.bottom)) widget.setAttribute('data-wyglad-w-przeplywie', '');
            } else {
                // Nie chowamy fokusu pod nagłówkiem, by odsłonić go spod widgetu.
                // Zwykły przepływ zachowuje dostęp przez Tab i mysz.
                widget.setAttribute('data-wyglad-w-przeplywie', '');
            }
        });
    });
    listen(hint.querySelector('button'), 'click', forgetHint);
    try { hint.hidden = localStorage.getItem('kuking-wyglad-poznany') === '1'; } catch { hint.hidden = true; }
    const odswiez = () => { geometry(); zaplanujPomiar(); };
    const observer = new ResizeObserver(odswiez);
    const nav = document.querySelector('.bottom-nav');
    if (nav) observer.observe(nav);
    listen(window, 'resize', odswiez);
    listen(window, 'scroll', odswiez);
    widget.dataset.wygladGotowy = '1';
    geometry();
    // Pierwszy pomiar od razu, bez odstępu: podpowiedź pokazuje się przy
    // pierwszej wizycie i potrafi przykryć treść, zanim ktokolwiek przewinie.
    zmierzZaslanianie();
    cleanup = () => {
        events.abort();
        observer.disconnect();
        if (pomiarZaplanowany !== null) clearTimeout(pomiarZaplanowany);
        hint.hidden = true;
    };
}
initialize();
document.addEventListener('livewire:navigating', () => cleanup());
document.addEventListener('livewire:navigated', initialize);
