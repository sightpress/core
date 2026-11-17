// Page screens of the admin panel. Both work without this script; it only adds to them:
// - the page tree (/admin/pages): a button before each list of subpages shows and hides it. Its name says what it
//   controls ("Show the pages under About") and aria-expanded whether it is open. Lists start closed;
// - the page editor: the character count of the description is kept up to date while typing. It sits in a polite
//   live region and changes only after a short pause, so screen readers aren't interrupted at every key.
(() => {
  'use strict';

  document.querySelectorAll('.page-tree ul.page-tree').forEach((list) => {
    const item = list.parentElement;
    const title = item?.querySelector(':scope > [data-page-title]')?.textContent?.trim()
      ?? item?.querySelector(':scope > .page-list__hidden')?.textContent?.trim() ?? '';
    const labels = list.closest('[data-show-label]');
    const show = labels?.getAttribute('data-show-label') ?? 'Show the pages under';
    const hide = labels?.getAttribute('data-hide-label') ?? 'Hide the pages under';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'page-tree__toggle button button--secondary';
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-controls', list.id);
    button.textContent = `${show} ${title}`;
    list.hidden = true;
    button.addEventListener('click', () => {
      const open = button.getAttribute('aria-expanded') !== 'true';
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.textContent = `${open ? hide : show} ${title}`;
      list.hidden = !open;
    });
    list.before(button);
  });

  document.querySelectorAll('[data-count-for]').forEach((count) => {
    const field = document.getElementById(count.getAttribute('data-count-for') ?? '');
    const max = Number(count.getAttribute('data-count-max') ?? '0');
    if (!(field instanceof HTMLTextAreaElement || field instanceof HTMLInputElement)) {
      return;
    }
    let timer = 0;
    field.addEventListener('input', () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => {
        const length = [...field.value].length;
        const over = max > 0 && length > max ? ` That is ${length - max} more than search engines show.` : '';
        count.textContent = `${length} characters. Search engines show about ${max}.${over}`;
      }, 750);
    });
  });
})();
