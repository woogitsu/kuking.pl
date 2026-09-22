// Natywne Tab może pozostawić część kolejnego linku poza szeroką tabelą.
// Odsłaniamy wyłącznie cel wewnątrz jej regionu, bez zmiany fokusu strony.
document.addEventListener('focusin', event => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const region = target.closest('[data-marka-panel] .tabela-kont-przewijanie');
    if (!region || region === target) return;
    const box = region.getBoundingClientRect();
    const item = target.getBoundingClientRect();
    const gap = 6;
    const left = box.left + region.clientLeft + gap;
    const top = box.top + region.clientTop + gap;
    const right = left + region.clientWidth - 2 * gap;
    const bottom = top + region.clientHeight - 2 * gap;
    const dx = item.left < left ? item.left - left : Math.max(0, item.right - right);
    const dy = item.top < top ? item.top - top : Math.max(0, item.bottom - bottom);
    if (dx) region.scrollLeft += dx;
    if (dy) region.scrollTop += dy;
});
