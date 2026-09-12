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
    , 'wake-lock': 'Ekran pozostanie włączony podczas gotowania.'
    , reply: 'Odpowiedź pojawi się pod tym komentarzem.'
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
  document.body.classList.toggle('guest-mode', ['powitanie', 'logowanie', 'rejestracja', 'onboarding'].includes(name));
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

const cookSteps = [
  ['Przygotuj ziemniaki', 'Zetrzyj ziemniaki i cebulę na drobnych oczkach. Przełóż na sito i dokładnie odciśnij nadmiar wody.', 'Im mniej wody zostanie w ziemniakach, tym bardziej chrupiące będą placki.'],
  ['Wymieszaj ciasto', 'Dodaj jajka, mąkę, sól i odrobinę majeranku. Wymieszaj tylko do połączenia składników.', 'Nie dosypuj zbyt dużo mąki — placki zrobią się ciężkie.'],
  ['Usmaż placki', 'Nakładaj niewielkie porcje na dobrze rozgrzany olej. Smaż z obu stron na mocno złoty kolor.', 'Gotowe placki odkładaj na papier, żeby pozbyć się nadmiaru tłuszczu.'],
  ['Zrób sos', 'Podsmaż grzyby z cebulą, dodaj śmietanę i duś około 10 minut. Dopraw solą i pieprzem.', 'Sos powinien lekko zgęstnieć, ale nadal łatwo spływać z łyżki.']
];
let cookStep = 0;
function updateCookStep() {
  document.querySelector('[data-cook-current]').textContent = cookStep + 1;
  document.querySelector('[data-cook-title]').textContent = cookSteps[cookStep][0];
  document.querySelector('[data-cook-instruction]').textContent = cookSteps[cookStep][1];
  document.querySelector('[data-cook-tip] p').textContent = cookSteps[cookStep][2];
  const prev = document.querySelector('[data-cook="prev"]');
  const next = document.querySelector('[data-cook="next"]');
  prev.disabled = cookStep === 0;
  next.textContent = cookStep === cookSteps.length - 1 ? 'Gotowe — pokaż efekt' : 'Gotowe, następny krok →';
  document.querySelectorAll('.cook-progress i').forEach((bar, index) => {
    bar.classList.toggle('is-active', index === cookStep);
    bar.classList.toggle('is-complete', index < cookStep);
  });
}
document.querySelector('.cooking-view')?.addEventListener('click', (event) => {
  const control = event.target.closest('[data-cook]');
  if (!control) return;
  if (control.dataset.cook === 'prev') cookStep = Math.max(0, cookStep - 1);
  if (control.dataset.cook === 'next' && cookStep < cookSteps.length - 1) cookStep += 1;
  else if (control.dataset.cook === 'next' && cookStep === cookSteps.length - 1) {
    showToast('Świetnie! Teraz możesz dodać zdjęcie i oznaczyć „Ugotowałem”.');
    history.pushState(null, '', '#przepis');
    showView('przepis');
  }
  updateCookStep();
});
updateCookStep();

document.querySelector('.comment-form')?.addEventListener('submit', (event) => {
  event.preventDefault();
  const field = document.querySelector('#comment-text');
  if (!field.value.trim()) { showToast('Napisz komentarz przed wysłaniem.'); field.focus(); return; }
  showToast('Komentarz został dodany.');
  field.value = '';
});

document.querySelectorAll('[data-text-size]').forEach((button) => {
  button.addEventListener('click', () => {
    document.documentElement.dataset.textSize = button.dataset.textSize;
    document.querySelectorAll('[data-text-size]').forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
    showToast('Wielkość tekstu została zmieniona.');
  });
});
document.querySelector('[data-setting="dark"]')?.addEventListener('change', (event) => {
  if (event.target.checked) document.documentElement.dataset.theme = 'dark'; else delete document.documentElement.dataset.theme;
  showToast(event.target.checked ? 'Włączono ciemny wygląd.' : 'Włączono jasny wygląd.');
});
document.querySelector('[data-setting="contrast"]')?.addEventListener('change', (event) => {
  if (event.target.checked) document.documentElement.dataset.contrast = 'high'; else delete document.documentElement.dataset.contrast;
  showToast(event.target.checked ? 'Włączono mocniejszy kontrast.' : 'Przywrócono zwykły kontrast.');
});

document.querySelectorAll('[data-auth]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    const destination = form.dataset.auth === 'register' ? 'onboarding' : 'start';
    showToast(form.dataset.auth === 'register' ? 'Konto zostało utworzone.' : 'Zalogowano pomyślnie.');
    history.pushState(null, '', `#${destination}`);
    showView(destination);
  });
});

document.querySelectorAll('.interest-grid button').forEach((button) => {
  button.addEventListener('click', () => {
    const selected = button.getAttribute('aria-pressed') === 'true';
    button.setAttribute('aria-pressed', String(!selected));
  });
});
document.querySelector('[data-onboarding="next"]')?.addEventListener('click', () => {
  const selected = document.querySelectorAll('.interest-grid button[aria-pressed="true"]').length;
  if (!selected) { showToast('Wybierz przynajmniej jeden temat albo kliknij „Na razie tylko pooglądam”.'); return; }
  showToast('Gotowe — strona główna została dopasowana.');
  history.pushState(null, '', '#start');
  showView('start');
});
document.querySelector('[data-onboarding="skip"]')?.addEventListener('click', () => {
  history.pushState(null, '', '#start');
  showView('start');
});

document.querySelectorAll('.people-list button').forEach((button) => {
  button.addEventListener('click', () => {
    const following = button.getAttribute('aria-pressed') === 'true';
    button.setAttribute('aria-pressed', String(!following));
    button.textContent = following ? 'Obserwuj' : 'Obserwujesz';
    showToast(following ? 'Przestajesz obserwować tę osobę.' : 'Od teraz zobaczysz jej wpisy na swojej stronie głównej.');
  });
});
