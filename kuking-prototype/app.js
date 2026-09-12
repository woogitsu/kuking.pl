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
    'load-more': 'W pełnej aplikacji pojawi się kolejna porcja wpisów.',
    'cook-mode': 'Tryb gotowania pokaże po jednym dużym kroku i uruchomi minutnik.',
    'new-folder': 'Tutaj wpiszesz nazwę nowego folderu.',
    'edit-profile': 'Tutaj zmienisz zdjęcie, nazwę i opis profilu.',
    'add-ingredient': 'Dodano miejsce na kolejny składnik.',
    'notification-open': 'Otwieram wskazaną treść.'
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

function showView(name) {
  const target = document.querySelector(`[data-view="${name}"]`);
  if (!target) return;
  document.querySelectorAll('[data-view]').forEach((view) => {
    const active = view === target;
    view.hidden = !active;
    view.classList.toggle('is-visible', active);
  });
  document.querySelectorAll('[data-route]').forEach((link) => {
    const active = link.dataset.route === name || (name === 'przepis' && link.dataset.route === 'odkrywaj');
    link.classList.toggle('is-active', active);
    if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
  });
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.addEventListener('click', (event) => {
  const route = event.target.closest('[data-route]');
  if (!route) return;
  event.preventDefault();
  history.pushState(null, '', route.getAttribute('href'));
  showView(route.dataset.route);
});

window.addEventListener('popstate', () => showView(location.hash.slice(1) || 'start'));
showView(location.hash.slice(1) || 'start');

document.querySelector('.search-panel')?.addEventListener('submit', (event) => {
  event.preventDefault();
  const phrase = document.querySelector('#search-input').value.trim();
  showToast(phrase ? `Wyniki dla: „${phrase}”.` : 'Wpisz nazwę dania albo składnik.');
});

document.querySelectorAll('.filter-chips button').forEach((button) => {
  button.addEventListener('click', () => {
    document.querySelectorAll('.filter-chips button').forEach((item) => item.setAttribute('aria-pressed', 'false'));
    button.setAttribute('aria-pressed', 'true');
    showToast(`Wybrano: ${button.textContent}.`);
  });
});

document.querySelector('[data-form="post"]')?.addEventListener('submit', (event) => {
  event.preventDefault();
  const text = document.querySelector('#post-text').value.trim();
  if (!text) {
    showToast('Napisz kilka słów o daniu, żeby opublikować wpis.');
    document.querySelector('#post-text').focus();
    return;
  }
  showToast('Wpis został opublikowany.');
  history.pushState(null, '', '#start');
  showView('start');
});

const wizard = document.querySelector('.recipe-wizard');
let wizardStep = 1;
function updateWizard() {
  wizard?.querySelectorAll('.wizard-step').forEach((step) => {
    const active = Number(step.dataset.step) === wizardStep;
    step.hidden = !active;
    step.classList.toggle('is-active', active);
  });
  const names = ['O przepisie', 'Składniki', 'Przygotowanie'];
  wizard.querySelector('[data-step-number]').textContent = wizardStep;
  wizard.querySelector('[data-step-name]').textContent = names[wizardStep - 1];
  wizard.querySelectorAll('.wizard-progress i').forEach((bar, index) => {
    bar.classList.toggle('is-active', index + 1 === wizardStep);
    bar.classList.toggle('is-complete', index + 1 < wizardStep);
  });
  const previous = wizard.querySelector('[data-wizard="prev"]');
  const next = wizard.querySelector('[data-wizard="next"]');
  previous.disabled = wizardStep === 1;
  next.textContent = wizardStep === 3 ? 'Opublikuj przepis' : 'Dalej';
}
wizard?.addEventListener('click', (event) => {
  const control = event.target.closest('[data-wizard]');
  if (!control) return;
  if (control.dataset.wizard === 'prev') wizardStep = Math.max(1, wizardStep - 1);
  if (control.dataset.wizard === 'next' && wizardStep < 3) wizardStep += 1;
  else if (control.dataset.wizard === 'next' && wizardStep === 3) {
    showToast('Przepis został opublikowany.');
    history.pushState(null, '', '#profil');
    showView('profil');
  }
  updateWizard();
});
updateWizard();

document.querySelector('.upload-zone')?.addEventListener('click', () => showToast('Tutaj otworzy się wybór zdjęcia z urządzenia.'));
document.querySelector('.upload-zone')?.addEventListener('keydown', (event) => {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault();
    showToast('Tutaj otworzy się wybór zdjęcia z urządzenia.');
  }
});

document.querySelectorAll('.people-list button').forEach((button) => {
  button.addEventListener('click', () => {
    const following = button.getAttribute('aria-pressed') === 'true';
    button.setAttribute('aria-pressed', String(!following));
    button.textContent = following ? 'Obserwuj' : 'Obserwujesz';
    showToast(following ? 'Przestajesz obserwować tę osobę.' : 'Od teraz zobaczysz jej wpisy na swojej stronie głównej.');
  });
});
