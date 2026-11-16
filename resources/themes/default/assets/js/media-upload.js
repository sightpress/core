// Upload progress for the media library's upload form (named by the script's data-form attribute). Instead of a
// plain form post, the file is sent with XMLHttpRequest so progress can be shown in the <progress> bar and announced
// in the polite live region (#media-upload-status) at 0, 25, 50, 75 and 100 %. The server answers with JSON naming
// the page to go to (the new file, or the library with the reason a file was refused). Without JavaScript, or if
// the request fails, the form is sent the usual way.
(() => {
  'use strict';

  const script = document.currentScript;
  const form = script ? document.getElementById(script.dataset.form ?? '') : null;
  const status = document.getElementById('media-upload-status');
  const bar = document.getElementById('media-upload-bar');
  if (!(form instanceof HTMLFormElement) || !status || !(bar instanceof HTMLProgressElement) || !window.FormData) {
    return;
  }
  const input = form.querySelector('input[type="file"]');
  const button = form.querySelector('button[type="submit"]');

  form.addEventListener('submit', (event) => {
    const file = input instanceof HTMLInputElement && input.files ? input.files[0] : null;
    if (!file) {
      return; // the server explains that a file is needed
    }
    event.preventDefault();

    const request = new XMLHttpRequest();
    let announced = -1;
    const announce = (percent) => {
      bar.value = percent;
      const step = Math.floor(percent / 25) * 25;
      if (step > announced) {
        announced = step;
        status.textContent = `Uploading ${file.name}: ${step}%`;
      }
    };

    bar.parentElement.hidden = false;
    if (button instanceof HTMLButtonElement) {
      button.disabled = true;
    }
    announce(0);
    request.upload.addEventListener('progress', (progress) => {
      if (progress.lengthComputable && progress.total > 0) {
        announce(Math.round((progress.loaded / progress.total) * 100));
      }
    });
    request.upload.addEventListener('load', () => {
      announce(100);
      status.textContent = `${file.name} has arrived. Checking the file…`;
    });
    request.addEventListener('load', () => {
      let location = null;
      try {
        location = JSON.parse(request.responseText).location;
      } catch {
        location = null;
      }
      if (typeof location === 'string' && location.startsWith('/')) {
        window.location.assign(location);
      } else {
        form.submit();
      }
    });
    request.addEventListener('error', () => form.submit());
    request.open('POST', form.action);
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.setRequestHeader('Accept', 'application/json');
    request.send(new FormData(form));
  });
})();
