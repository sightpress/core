// SightPress default theme. Pages work without this script; it only adds to them:
// - submenus of the main menu become disclosure buttons (without the script every level is shown);
// - invoker buttons (`commandfor` / `command="show-modal"` and `"close"` on a <dialog>) for browsers without them.
(() => {
  'use strict';

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
