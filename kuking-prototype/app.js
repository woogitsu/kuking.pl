const toast = document.querySelector('.toast');
let toastTimer;

function showToast(message) {
  window.clearTimeout(toastTimer);
  toast.textContent = message;
  toast.hidden = false;
  toastTimer = window.setTimeout(() => { toast.hidden = true; }, 5500);
}

document.addEventListener('click', (event) => {
  const control = event.target.closest('[data-action]');
  if (!control) return;

  const action = control.dataset.action;
  const messages = {
    search: 'W pełnej aplikacji otworzy się wyszukiwarka osób, dań i składników.',
    'add-photo': 'Tutaj otworzy się prosty formularz: zdjęcie, kilka słów i widoczność.',
    'add-recipe': 'Tutaj rozpocznie się trzyetapowy kreator przepisu.',
    'feed-info': 'Wpisy są ułożone chronologicznie — od najnowszych, bez ukrytego rankingu.',
    cooked: 'Formularz „Ugotowałem” pozwoli dodać zdjęcie i krótką uwagę.',
    comment: 'Tutaj otworzy się rozmowa pod wpisem.',
    'load-more': 'W pełnej aplikacji pojawi się kolejna porcja wpisów.'
  };

  if (action === 'save') {
    const saved = control.getAttribute('aria-pressed') === 'true';
    control.setAttribute('aria-pressed', String(!saved));
    control.innerHTML = saved
      ? '<span aria-hidden="true">▱</span> Do zeszytu'
      : '<span aria-hidden="true">✓</span> W zeszycie';
    showToast(saved ? 'Usunięto z zeszytu.' : 'Zapisano w Twoim zeszycie.');
    return;
  }

  showToast(messages[action] || 'Ta funkcja zostanie podłączona do aplikacji Laravel.');
});

document.querySelectorAll('.people-list button').forEach((button) => {
  button.addEventListener('click', () => {
    const following = button.getAttribute('aria-pressed') === 'true';
    button.setAttribute('aria-pressed', String(!following));
    button.textContent = following ? 'Obserwuj' : 'Obserwujesz';
    showToast(following ? 'Przestajesz obserwować tę osobę.' : 'Od teraz zobaczysz jej wpisy na swojej stronie głównej.');
  });
});
