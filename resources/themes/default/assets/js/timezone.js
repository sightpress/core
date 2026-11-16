// Prefills a time zone <select> with the browser's time zone, when the browser can say and the list has it. The
// select is named by the script's data-select attribute. Without JavaScript the server's choice stays selected.
(() => {
  'use strict';

  const script = document.currentScript;
  const select = script ? document.getElementById(script.dataset.select ?? '') : null;
  if (!(select instanceof HTMLSelectElement)) {
    return;
  }
  const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
  if (zone && Array.from(select.options).some((option) => option.value === zone)) {
    select.value = zone;
  }
})();
