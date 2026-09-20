/* Podpowiadamy tylko aktywny hashtag. Opis i ręczne tag_names[] zostają źródłem formularza. */
export function aktywnyTag(text, start, end = start) {
    if (start !== end) return null;
    const before = text.slice(0, start);
    const match = /(?:^|[\s(\[{"'«„])#([\p{L}\p{M}\p{N}_-]*)$/u.exec(before);
    if (!match) return null;
    const from = start - match[1].length - 1;
    const tail = /^[\p{L}\p{M}\p{N}_-]*/u.exec(text.slice(start))[0];
    const query = match[1].normalize('NFC');
    if ((match[1] + tail).includes('_')) return null;
    return { from, to: start + tail.length, query };
}
export function licznikWpisow(count) {
    const n = Number(count);
    if (!Number.isSafeInteger(n) || n < 0) return null;
    const noun = n === 1 ? 'publiczny wpis' : n % 10 >= 2 && n % 10 <= 4 && !(n % 100 >= 12 && n % 100 <= 14) ? 'publiczne wpisy' : 'publicznych wpisów';
    return `${n} ${noun}`;
}
export function nastepnaOpcja(active, length, direction) {
    if (!length) return -1;
    if (active < 0) return direction === 'ArrowUp' ? length - 1 : 0;
    return (active + (direction === 'ArrowDown' ? 1 : -1) + length) % length;
}
function setup(root, index) {
    const input = root.querySelector('textarea[name="body"]');
    if (!input || root.dataset.tagiReady) return;
    root.dataset.tagiReady = '1';
    const list = document.createElement('div');
    list.className = 'tagi-opis-popup'; list.id = `tagi-opis-${index}`;
    list.setAttribute('role', 'listbox'); list.setAttribute('aria-label', 'Podpowiedzi tagów'); list.hidden = true;
    const status = document.createElement('p'); status.className = 'field-help'; status.setAttribute('role', 'status');
    root.append(status, list);
    // Opis pozostaje wielowierszowym textboxem; aria-expanded nie należy do tej roli.
    input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-controls', list.id); input.setAttribute('aria-haspopup', 'listbox');
    let timer, controller, sequence = 0, composing = false, items = [], active = -1, current = null, observed = null;
    const token = () => aktywnyTag(input.value, input.selectionStart, input.selectionEnd);
    const key = t => t && `${t.from}:${t.to}:${t.query}:${input.selectionStart}`;
    function hide() {
        clearTimeout(timer); controller?.abort(); sequence++; list.hidden = true; items = []; active = -1;
        input.removeAttribute('aria-activedescendant');
    }
    function position() {
        if (list.hidden) return;
        const box = input.getBoundingClientRect(), css = getComputedStyle(input);
        const mirror = document.createElement('div');
        for (const property of ['fontFamily','fontSize','fontWeight','fontStyle','lineHeight','letterSpacing','textTransform','textIndent','paddingTop','paddingRight','paddingBottom','paddingLeft','borderTopWidth','borderRightWidth','borderBottomWidth','borderLeftWidth','boxSizing','wordSpacing','tabSize']) mirror.style[property] = css[property];
        Object.assign(mirror.style, { position: 'fixed', visibility: 'hidden', whiteSpace: 'pre-wrap', overflowWrap: 'break-word', width: `${box.width}px`, left: '0', top: '0', borderStyle: 'solid' });
        mirror.textContent = input.value.slice(0, input.selectionStart);
        const marker = document.createElement('span'); marker.textContent = '\u200b'; mirror.append(marker); document.body.append(mirror);
        const caret = marker.getBoundingClientRect(), origin = mirror.getBoundingClientRect();
        const x = box.left + caret.left - origin.left - input.scrollLeft;
        const y = box.top + caret.top - origin.top - input.scrollTop;
        mirror.remove();
        const viewport = window.visualViewport;
        const left = viewport?.offsetLeft || 0, top = viewport?.offsetTop || 0;
        const width = viewport?.width || document.documentElement.clientWidth, height = viewport?.height || innerHeight;
        const gap = 8, popupWidth = Math.min(360, width - gap * 2);
        list.style.width = `${Math.max(0, popupWidth)}px`;
        list.style.left = `${Math.max(left + gap, Math.min(x, left + width - popupWidth - gap))}px`;
        if (y + (parseFloat(css.lineHeight) || 24) < box.top || y > box.bottom) { hide(); return; }
        const baseline = Math.max(top + gap, Math.min(y, top + height - gap));
        const line = parseFloat(css.lineHeight) || parseFloat(css.fontSize) * 1.5;
        const below = top + height - baseline - line - gap, above = baseline - top - gap;
        const useBelow = below >= Math.min(240, above);
        list.style.maxHeight = `${Math.max(0, Math.min(320, useBelow ? below : above))}px`;
        list.style.top = `${useBelow ? baseline + line : Math.max(top + gap, baseline - list.offsetHeight)}px`;
    }
    function highlight(index) {
        active = index;
        [...list.children].forEach((el, i) => el.setAttribute('aria-selected', String(i === active)));
        if (active >= 0) { input.setAttribute('aria-activedescendant', list.children[active].id); list.children[active].scrollIntoView({ block: 'nearest' }); }
        else input.removeAttribute('aria-activedescendant');
    }
    function choose(index) {
        if (!items[index] || composing || key(token()) !== current) { hide(); return; }
        const t = token(), replacement = '#' + items[index].token;
        input.setRangeText(replacement, t.from, t.to, 'end');
        hide();
        input.focus(); input.dispatchEvent(new Event('input', { bubbles: true }));
        // Wybór nie otwiera ponownie listy i nigdy nie wysyła formularza.
        hide();
        observed = key(token());
        status.textContent = 'Tag jest w opisie. Możesz pisać dalej.';
    }
    async function search(t, stamp, expected) {
        controller = new AbortController();
        try {
            const url = new URL(root.dataset.tagiEndpoint, location.href);
            if (url.origin !== location.origin) throw new Error('origin');
            url.searchParams.set('q', t.query);
            const response = await fetch(url, { signal: controller.signal, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('transport');
            const data = await response.json();
            if (stamp !== sequence || key(token()) !== expected || document.activeElement !== input) return;
            if (!Array.isArray(data.tags) || typeof data.can_create !== 'boolean') throw new Error('shape');
            items = data.tags.filter(tag => typeof tag.name === 'string' && typeof tag.slug === 'string' && /^[\p{L}\p{M}\p{N}-]+$/u.test(tag.slug) && licznikWpisow(tag.public_posts_count) !== null)
                .map(tag => ({ token: tag.slug, label: `#${tag.name} — ${licznikWpisow(tag.public_posts_count)}` }));
            if (data.can_create && !data.exact_match && /^[\p{L}\p{M}\p{N}-]+$/u.test(t.query)) items.push({ token: t.query, label: `Dodaj #${t.query} jako nowy tag` });
            list.replaceChildren(); active = -1; current = expected;
            items.forEach((item, i) => {
                const option = document.createElement('div'); option.className = 'tagi-opis-opcja'; option.id = `${list.id}-${i}`;
                option.setAttribute('role', 'option'); option.setAttribute('aria-selected', 'false'); option.textContent = item.label;
                option.addEventListener('pointerdown', e => e.preventDefault()); option.addEventListener('click', () => choose(i)); list.append(option);
            });
            list.hidden = items.length === 0;
            status.textContent = items.length ? 'Wybierz tag z podpowiedzi albo pisz dalej.' : 'Brak podpowiedzi. Możesz skorzystać z wyszukiwania tagów poniżej.';
            position();
        } catch (error) {
            if (error.name === 'AbortError' || stamp !== sequence) return;
            hide(); status.textContent = 'Nie udało się pobrać podpowiedzi. Spróbuj ponownie albo znajdź tag poniżej.';
        }
    }
    function update() {
        if (composing || document.activeElement !== input) return;
        const t = token(); observed = key(t); hide(); status.textContent = '';
        if (!t || [...t.query].length < Number(root.dataset.tagiMin) || [...t.query].length > Number(root.dataset.tagiMax)) return;
        const stamp = sequence, expected = key(t);
        timer = setTimeout(() => search(t, stamp, expected), 220);
    }
    document.addEventListener('selectionchange', () => { if (document.activeElement === input && key(token()) !== observed) update(); });
    input.addEventListener('input', update); input.addEventListener('click', update);
    // setRangeText emituje też opóźnione select; ten sam kursor nie jest nową edycją.
    input.addEventListener('select', () => { if (key(token()) !== observed) update(); });
    input.addEventListener('compositionstart', () => { composing = true; hide(); });
    input.addEventListener('compositionend', () => { composing = false; update(); });
    input.addEventListener('keydown', e => {
        if (composing || e.isComposing || list.hidden) return;
        if (e.key === 'Escape') { e.preventDefault(); hide(); }
        else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); highlight(nastepnaOpcja(active, items.length, e.key)); }
        else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(active); }
        else if (e.key === 'Tab') hide();
    });
    input.addEventListener('keyup', e => { if (['ArrowLeft','ArrowRight','Home','End'].includes(e.key)) update(); });
    input.addEventListener('blur', hide); input.addEventListener('scroll', position);
    window.addEventListener('resize', position); window.addEventListener('scroll', position, true);
    window.visualViewport?.addEventListener('resize', position); window.visualViewport?.addEventListener('scroll', position);
}
function init() { document.querySelectorAll('[data-tagi-opis]').forEach(setup); }
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true }); else init();
}
