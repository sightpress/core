// SightPress default theme. Pages work without this script; it only adds to them:
// - submenus of the main menu become disclosure buttons (without the script every level is shown);
// - invoker buttons (`commandfor` / `command="show-modal"` and `"close"` on a <dialog>) for browsers without them;
// - a screen that comes back at a heading after a change (`/admin/features#feature-…`) moves focus to it, so screen
//   readers start there (without the script the page still scrolls to it and Tab continues from it);
// - a file field with `data-max-bytes` refuses a file that is too large before the form is sent, with an error under
//   its label (the server checks again).
(() => {
  'use strict';

  const target = window.location.hash.length > 1 ? document.getElementById(decodeURIComponent(window.location.hash.slice(1))) : null;
  if (target instanceof HTMLElement && /^H[1-6]$/.test(target.tagName) && target.getAttribute('tabindex') === '-1') {
    target.focus();
  }

  // Main menu: a button after each link that has a submenu shows and hides it. Its name says what it controls
  // ("Show the pages under About"); `aria-expanded` says whether it is open. Escape closes the submenu that has
  // focus and moves focus back to its button.
  document.querySelectorAll('.menu--main li > ul').forEach((submenu) => {
    const label = submenu.closest('nav')?.getAttribute('data-submenu-label') ?? 'Show the pages under';
    const link = submenu.parentElement?.querySelector(':scope > a');
    if (!(link instanceof HTMLAnchorElement) || submenu.id === '') {
      return;
    }
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'menu__toggle';
    button.setAttribute('aria-expanded', submenu.querySelector('[aria-current="page"]') ? 'true' : 'false');
    button.setAttribute('aria-controls', submenu.id);
    button.setAttribute('aria-label', `${label} ${link.textContent?.trim() ?? ''}`);
    button.innerHTML = '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3 6l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2"/></svg>';
    button.addEventListener('click', () => {
      button.setAttribute('aria-expanded', button.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');
    });
    submenu.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        button.setAttribute('aria-expanded', 'false');
        button.focus();
      }
    });
    submenu.before(button);
  });

  // File size check: `data-max-bytes` is one limit or, by the start of the file's type, {"image": …, "video": …,
  // "audio": …, "*": …}; `data-size-error` is the translated message with {name}, {size} and {max}. Runs before any
  // other submit handler (capture on the document), so a refused file is never sent. The error is shown like the
  // server's, linked to the field, and the field gets focus; choosing another file removes it.
  const sizeText = (bytes) => {
    const units = ['KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 'bytes';
    for (const next of units) {
      if (value < 1024) {
        break;
      }
      value /= 1024;
      unit = next;
    }
    return unit === 'bytes' ? `${bytes} bytes` : `${value < 10 ? Math.round(value * 10) / 10 : Math.round(value)} ${unit}`;
  };
  const limitFor = (input, file) => {
    const raw = input.dataset.maxBytes ?? '';
    if (/^\d+$/.test(raw)) {
      return Number(raw);
    }
    try {
      const limits = JSON.parse(raw);
      const kind = (file.type || '').split('/')[0];
      const limit = limits[kind] ?? limits['*'];
      return typeof limit === 'number' ? limit : null;
    } catch {
      return null;
    }
  };
  const clearSizeError = (input) => {
    const error = document.getElementById(`${input.id}-size-error`);
    if (!error) {
      return;
    }
    error.remove();
    const described = (input.getAttribute('aria-describedby') ?? '').split(' ').filter((id) => id !== '' && id !== error.id);
    if (described.length > 0) {
      input.setAttribute('aria-describedby', described.join(' '));
    } else {
      input.removeAttribute('aria-describedby');
    }
    if (!document.getElementById(`${input.id}-error`)) {
      input.removeAttribute('aria-invalid');
      input.closest('.field')?.classList.remove('field--error');
    }
  };
  document.addEventListener('change', (event) => {
    if (event.target instanceof HTMLInputElement && event.target.type === 'file' && event.target.dataset.maxBytes) {
      clearSizeError(event.target);
    }
  });
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    for (const input of form.querySelectorAll('input[type="file"][data-max-bytes]')) {
      const file = input.files && input.files[0];
      const max = file ? limitFor(input, file) : null;
      if (!file || max === null || file.size <= max) {
        continue;
      }
      event.preventDefault();
      event.stopImmediatePropagation();
      clearSizeError(input);
      const error = document.createElement('p');
      error.className = 'field-error';
      error.id = `${input.id}-size-error`;
      const prefix = document.createElement('span');
      prefix.className = 'visually-hidden';
      prefix.textContent = input.dataset.errorPrefix ?? 'Error:';
      error.append(prefix, ` ${(input.dataset.sizeError ?? '{name} is too large ({size}). It can be at most {max}.')
        .replace('{name}', file.name).replace('{size}', sizeText(file.size)).replace('{max}', sizeText(max))}`);
      input.before(error);
      input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
      input.setAttribute('aria-invalid', 'true');
      input.closest('.field')?.classList.add('field--error');
      input.focus();
      return;
    }
  }, true);

  // Invoker buttons, for browsers without them (the rest of this script).
  if ('command' in HTMLButtonElement.prototype) {
    return;
  }

  document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('button[commandfor]') : null;
    if (!button) {
      return;
    }
    const target = document.getElementById(button.getAttribute('commandfor') ?? '');
    if (!(target instanceof HTMLDialogElement)) {
      return;
    }
    const command = button.getAttribute('command');
    if (command === 'show-modal' && !target.open) {
      target.showModal();
    } else if (command === 'close' && target.open) {
      target.close();
    }
  });
})();
