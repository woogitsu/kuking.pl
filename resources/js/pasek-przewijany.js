// Pasek gościa wraca przy przewijaniu w górę. Bez JS pozostaje przypięty.
let cleanup = () => {};
function initialize() {
    cleanup();
    const bar = document.querySelector('[data-pasek-przewijany]');
    if (!bar) return;
    let previous = Math.max(0, window.scrollY);
    let distance = 0;
    let frame = 0;
    const show = () => bar.removeAttribute('data-pasek-schowany');
    const update = () => {
        frame = 0;
        const y = Math.max(0, Math.min(window.scrollY, document.documentElement.scrollHeight - window.innerHeight));
        const delta = y - previous;
        previous = y;
        if (getComputedStyle(bar).position !== 'sticky' || y <= bar.offsetHeight || bar.contains(document.activeElement) || bar.querySelector('[aria-expanded="true"], details[open]')) {
            distance = 0;
            show();
            return;
        }
        if (Math.sign(delta) !== Math.sign(distance)) distance = 0;
        distance += delta;
        if (distance <= -8) show();
        if (distance >= 8) bar.setAttribute('data-pasek-schowany', '');
    };
    const schedule = () => { if (!frame) frame = requestAnimationFrame(update); };
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);
    bar.addEventListener('focusin', show);
    cleanup = () => {
        window.removeEventListener('scroll', schedule);
        window.removeEventListener('resize', schedule);
        bar.removeEventListener('focusin', show);
        cancelAnimationFrame(frame);
        show();
    };
}
initialize();
document.addEventListener('livewire:navigated', initialize);
document.addEventListener('livewire:navigating', () => cleanup());
