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
    };
    const geometry = () => {
        if (pointerOnFlowSummary) return;
        const wasInFlow = widget.hasAttribute('data-wyglad-w-przeplywie');
        if (wasInFlow) widget.removeAttribute('data-wyglad-w-przeplywie');
        const nav = document.querySelector('.bottom-nav');
        const rect = nav?.getBoundingClientRect();
        // Rezerwa pod stopką = zmierzona wysokość PRZYPIĘTEJ belki, raz
        // (`--rezerwa-ukladu-dol` w marka-rama.css). Belka ukryta albo
        // przewijana ze stroną niczego nie zasłania, więc rezerwa to 0.
        const pinned = rect && rect.height > 0 && getComputedStyle(nav).position === 'fixed';
        document.documentElement.style.setProperty('--rezerwa-belki', pinned ? Math.ceil(innerHeight - rect.top) + 'px' : '0px');
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
            return true;
        } catch {
            if (widget.isConnected && item.revision === revision) {
                scale.value = saved.text_scale;
                theme.value = saved.theme;
                apply(saved);
                // Utrata odpowiedzi nie dowodzi, że serwer nie zapisał wyboru.
                status.textContent = 'Nie mamy potwierdzenia zapisu wyglądu. Wybierz ustawienie ponownie albo kliknij link jeszcze raz, aby przejść dalej.';
                widget.open = true;
                geometry();
            }
            return false;
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
        completion.then(saved => { if (saved) location.href = link.href; });
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
    // Podpowiedź pierwszej wizyty stoi `position: fixed` nad treścią i przy
    // niskim oknie potrafi przykryć sterowanie w całości — zmierzone na
    // przełączniku motywu w stopce: 3536 px² przykrycia, 9 z 9 punktów
    // próbnych trafiało w podpowiedź, a nie w przycisk (#684).
    //
    // Klawiatura wychodziła z tego sama: uchwyt `focusin` niżej ustawia
    // `hint.hidden = true`, gdy fokus trafi gdziekolwiek poza widget. Mysz
    // i dotyk nie wywołują `focusin` na przykrytym elemencie, więc nie
    // dostawały NICZEGO i podpowiedź zostawała nad celem na stałe.
    //
    // Te dwa zdarzenia to ten sam sygnał „czytam stronę, nie podpowiedź",
    // co `focusin`, tylko dla wskaźnika: `wheel` to kółko myszy, `pointerdown`
    // poza podpowiedzią to dotknięcie albo kliknięcie. Świadomie NIE słuchamy
    // `scroll` — ten leci także po `window.scrollTo` z kodu i chowałby
    // podpowiedź bez udziału człowieka.
    //
    // `hidden` bez `forgetHint()`: to nie jest „Rozumiem". Nie zapisujemy
    // w localStorage, więc podpowiedź wróci przy następnej wizycie i nadal
    // zrobi swoje. Zabieramy jej wyłącznie prawo do blokowania celu.
    // `touchstart` obok `pointerdown`, bo przewijanie palcem nie zawsze
    // przechodzi przez zdarzenia wskaźnika — zmierzone w Chromium: sam
    // `pointerdown` nie wystarczył, podpowiedź zostawała nad celem.
    const ustapWskaznikowi = () => { if (!hint.hidden) hint.hidden = true; };
    const pozaPodpowiedzia = event => { if (!hint.contains(event.target)) ustapWskaznikowi(); };
    listen(document, 'wheel', ustapWskaznikowi, {passive: true});
    listen(document, 'pointerdown', pozaPodpowiedzia);
    listen(document, 'touchstart', pozaPodpowiedzia, {passive: true});
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
    const observer = new ResizeObserver(geometry);
    const nav = document.querySelector('.bottom-nav');
    if (nav) observer.observe(nav);
    listen(window, 'resize', geometry);
    listen(window, 'scroll', geometry);
    widget.dataset.wygladGotowy = '1';
    geometry();
    cleanup = () => { events.abort(); observer.disconnect(); hint.hidden = true; };
}
initialize();
document.addEventListener('livewire:navigating', () => cleanup());
document.addEventListener('livewire:navigated', initialize);
