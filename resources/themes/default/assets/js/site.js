// SightPress default theme. Pages work without this script; it only fills in for browsers that don't support
// invoker buttons yet (`commandfor` / `command="show-modal"` and `"close"` on a <dialog>).
(() => {
  'use strict';

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
