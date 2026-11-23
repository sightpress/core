// Uploads from the media library's form (named by the script's data-form attribute). A file that fits in one part is
// sent as a normal form post with XMLHttpRequest. A larger one is sent in parts with tus 1.0 to /admin/media/uploads:
// it survives a lost connection and a page reload (choosing the same file again resumes it), and can be paused,
// resumed and cancelled. Progress is in the <progress> bar and announced in the polite live region
// (#media-upload-status) every 10 %. The server names the page to go to when the upload ends. Without JavaScript the
// form is sent the usual way. On a site with cloud storage, large files go straight from the browser to the bucket
// with signed part addresses, and through the server only when the bucket doesn't allow it.
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
  const controls = document.getElementById('media-upload-controls');
  const pause = document.getElementById('media-upload-pause');
  const resume = document.getElementById('media-upload-resume');
  const cancel = document.getElementById('media-upload-cancel');
  const data = script.dataset;
  const chunkBytes = Number(data.chunkBytes) || 0;
  const RETRIES = 5;

  const say = (template, values) => Object.entries(values).reduce((text, [key, value]) => text.replaceAll(`{${key}}`, String(value)), template ?? '');
  const csrf = () => ({
    'X-CSRF-Name': form.querySelector('input[name="csrf_name"]')?.value ?? '',
    'X-CSRF-Value': form.querySelector('input[name="csrf_value"]')?.value ?? '',
  });
  const storageKey = (file) => `sightpress.upload:${data.uploads}:${file.name}:${file.size}:${file.lastModified}`;
  const remember = (key, value) => { try { localStorage.setItem(key, value); } catch { /* private browsing */ } };
  const recall = (key) => { try { return localStorage.getItem(key); } catch { return null; } };
  const forget = (key) => { try { localStorage.removeItem(key); } catch { /* private browsing */ } };
  const base64 = (text) => btoa(String.fromCharCode(...new TextEncoder().encode(text)));
  const wait = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

  const tus = (method, url, headers = {}, body = null, onProgress = null, onRequest = null) => new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open(method, url);
    request.setRequestHeader('Tus-Resumable', '1.0.0');
    Object.entries(headers).forEach(([name, value]) => request.setRequestHeader(name, value));
    if (onProgress) {
      request.upload.addEventListener('progress', (event) => onProgress(event.loaded));
    }
    request.addEventListener('load', () => resolve(request));
    request.addEventListener('error', () => reject(new Error('network')));
    request.addEventListener('abort', () => reject(new DOMException('stopped', 'AbortError')));
    onRequest?.(request);
    request.send(body);
  });

  // How long the server asks to wait after a 429, within reason.
  const retryDelay = (answer) => Math.min(60, Math.max(1, Number(answer.getResponseHeader('Retry-After')) || 5)) * 1000;

  const checksum = async (blob) => {
    if (!window.crypto?.subtle) {
      return null; // only on secure pages; the size is still checked
    }
    const digest = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());

    return `sha256 ${btoa(String.fromCharCode(...new Uint8Array(digest)))}`;
  };

  const sendInParts = async (file) => {
    const key = storageKey(file);
    let url = recall(key);
    let offset = 0;
    let size = chunkBytes;
    let paused = false;
    let cancelled = false;
    let current = null;
    let wake = null;
    let announced = -10;

    const show = (bytes) => {
      const percent = Math.min(100, Math.floor((bytes / file.size) * 100));
      bar.value = percent;
      const step = Math.floor(percent / 10) * 10;
      if (step > announced) {
        announced = step;
        status.textContent = say(data.textProgress, { name: file.name, percent: step });
      }
    };
    const setPaused = (value, message) => {
      paused = value;
      pause.hidden = value;
      resume.hidden = !value;
      (value ? resume : pause).focus();
      status.textContent = message;
    };
    const percentNow = () => Math.floor((offset / file.size) * 100);
    const onPause = () => {
      setPaused(true, say(data.textPaused, { name: file.name, percent: percentNow() }));
      current?.abort();
    };
    const onResume = () => {
      setPaused(false, say(data.textResumed, { name: file.name, percent: percentNow() }));
      wake?.();
    };
    const onCancel = async () => {
      cancelled = true;
      current?.abort();
      wake?.();
    };
    pause.addEventListener('click', onPause);
    resume.addEventListener('click', onResume);
    cancel.addEventListener('click', onCancel);
    const stopListening = () => {
      pause.removeEventListener('click', onPause);
      resume.removeEventListener('click', onResume);
      cancel.removeEventListener('click', onCancel);
    };
    const finish = (location) => {
      stopListening();
      forget(key);
      window.location.assign(location);
    };
    const reset = () => {
      stopListening();
      controls.hidden = true;
      bar.parentElement.hidden = true;
      bar.value = 0;
      if (button instanceof HTMLButtonElement) {
        button.disabled = false;
      }
      button?.focus();
    };

    controls.hidden = false;
    pause.hidden = false;
    resume.hidden = true;
    bar.parentElement.hidden = false;
    if (button instanceof HTMLButtonElement) {
      button.disabled = true;
    }

    if (url) {
      const found = await tus('HEAD', url).catch(() => null);
      if (found?.status === 200) {
        offset = Number(found.getResponseHeader('Upload-Offset')) || 0;
        size = Number(found.getResponseHeader('X-Chunk-Bytes')) || size;
        const done = found.getResponseHeader('X-Media-Location');
        if (done) {
          finish(done);
          return;
        }
      } else {
        forget(key);
        url = null;
      }
    }
    if (!url) {
      const metadata = `filename ${base64(file.name)},filetype ${base64(file.type)}`;
      const create = () => tus('POST', data.uploads, { ...csrf(), 'Upload-Length': String(file.size), 'Upload-Metadata': metadata });
      let created = await create();
      for (let tries = 0; created.status === 429 && tries < RETRIES; tries += 1) {
        await wait(retryDelay(created));
        created = await create();
      }
      const refused = created.getResponseHeader('X-Media-Location');
      if (created.status !== 201) {
        stopListening();
        if (refused) {
          window.location.assign(refused);
        } else {
          form.submit();
        }
        return;
      }
      url = created.getResponseHeader('Location');
      size = Number(created.getResponseHeader('X-Chunk-Bytes')) || size;
      remember(key, url);
    }
    show(offset);

    let failures = 0;
    for (;;) {
      if (cancelled) {
        await tus('DELETE', url, csrf()).catch(() => null);
        forget(key);
        reset();
        status.textContent = say(data.textCancelled, { name: file.name });
        return;
      }
      if (paused) {
        await new Promise((resolve) => { wake = resolve; });
        continue;
      }
      const part = file.slice(offset, offset + size);
      try {
        const headers = { ...csrf(), 'Content-Type': 'application/offset+octet-stream', 'Upload-Offset': String(offset) };
        const sum = await checksum(part);
        if (sum) {
          headers['Upload-Checksum'] = sum;
        }
        if (offset + part.size >= file.size) {
          if (controls.contains(document.activeElement)) {
            status.tabIndex = -1; // keep keyboard focus on the page when the buttons go away
            status.focus();
          }
          controls.hidden = true; // the file is being checked: it can't be stopped any more
        }
        const start = offset;
        const answer = await tus('PATCH', url, headers, part, (sent) => show(start + sent), (request) => { current = request; });
        current = null;
        const location = answer.getResponseHeader('X-Media-Location');
        if (answer.status === 204) {
          failures = 0;
          offset = Number(answer.getResponseHeader('Upload-Offset'));
          show(offset);
          if (location) {
            finish(location);
            return;
          }
          if (offset >= file.size) {
            status.textContent = say(data.textChecking, { name: file.name });
          }
          continue;
        }
        if (location) {
          stopListening();
          forget(key);
          window.location.assign(location);
          return;
        }
        if (answer.status === 429) {
          await wait(retryDelay(answer)); // not a failure: the part is sent again once the server is ready
          continue;
        }
        if (answer.status !== 409 && answer.status !== 460) {
          throw new Error('refused');
        }
        failures += 1;
        if (failures > RETRIES) {
          throw new Error('refused');
        }
        const found = await tus('HEAD', url);
        offset = Number(found.getResponseHeader('Upload-Offset')) || offset;
      } catch (error) {
        current = null;
        if (error instanceof DOMException && error.name === 'AbortError') {
          continue; // paused or cancelled
        }
        failures += 1;
        controls.hidden = false;
        if (failures > RETRIES) {
          failures = 0;
          setPaused(true, data.textOffline);
          continue;
        }
        status.textContent = data.textRetrying;
        await wait(1000 * 2 ** (failures - 1));
        const found = await tus('HEAD', url).catch(() => null);
        if (found?.status === 200) {
          offset = Number(found.getResponseHeader('Upload-Offset')) || 0;
        }
      }
    }
  };

  const api = (method, url, body) => new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open(method, url);
    Object.entries(csrf()).forEach(([name, value]) => request.setRequestHeader(name, value));
    request.setRequestHeader('Accept', 'application/json');
    if (body !== undefined) {
      request.setRequestHeader('Content-Type', 'application/json');
    }
    request.addEventListener('load', () => {
      let json = {};
      try { json = JSON.parse(request.responseText || '{}'); } catch { json = {}; }
      resolve({ status: request.status, json, retryAfter: request.getResponseHeader('Retry-After') });
    });
    request.addEventListener('error', () => reject(new Error('network')));
    request.send(body === undefined ? null : JSON.stringify(body));
  });

  // One part, straight to the bucket. No header is added: the address is signed for exactly this request.
  const putPart = (url, blob, onProgress, onRequest) => new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open('PUT', url);
    request.upload.addEventListener('progress', (event) => onProgress(event.loaded));
    request.addEventListener('load', () => resolve({ status: request.status, etag: request.getResponseHeader('ETag') }));
    request.addEventListener('error', () => reject(new Error('network')));
    request.addEventListener('abort', () => reject(new DOMException('stopped', 'AbortError')));
    onRequest(request);
    request.send(blob);
  });

  class UseServer extends Error {}

  // Sends a file straight to the bucket. Falls back to the server (sendInParts) when the bucket can't take it.
  const sendDirect = async (file) => {
    const key = `${storageKey(file)}:direct`;
    let id = recall(key);
    let plan = null;
    let received = {};
    if (id) {
      const found = await api('GET', `${data.direct}/${id}`).catch(() => null);
      if (found?.status === 200 && found.json.location) {
        forget(key);
        window.location.assign(found.json.location);
        return;
      }
      if (found?.status === 200 && found.json.state === 'receiving') {
        plan = found.json;
        received = found.json.received ?? {};
      } else if (found?.status === 200) {
        plan = { validating: true };
      } else {
        forget(key);
        id = null;
      }
    }
    if (!plan) {
      const started = await api('POST', data.direct, { name: file.name, type: file.type, size: file.size });
      if (started.json.location) {
        window.location.assign(started.json.location);
        return;
      }
      if (started.status !== 201) {
        throw new UseServer();
      }
      plan = started.json;
      ({ id } = started.json);
      remember(key, id);
    }

    let paused = false;
    let cancelled = false;
    let current = null;
    let wake = null;
    let announced = -10;
    const done = () => Object.keys(received).reduce((sum, n) => sum + Math.min(plan.partBytes, file.size - (Number(n) - 1) * plan.partBytes), 0);
    const show = (bytes) => {
      const percent = Math.min(100, Math.floor((bytes / file.size) * 100));
      bar.value = percent;
      const step = Math.floor(percent / 10) * 10;
      if (step > announced) {
        announced = step;
        status.textContent = say(data.textProgress, { name: file.name, percent: step });
      }
    };
    const percentNow = () => Math.floor((done() / file.size) * 100);
    const setPaused = (value, message) => {
      paused = value;
      pause.hidden = value;
      resume.hidden = !value;
      (value ? resume : pause).focus();
      status.textContent = message;
    };
    const onPause = () => { setPaused(true, say(data.textPaused, { name: file.name, percent: percentNow() })); current?.abort(); };
    const onResume = () => { setPaused(false, say(data.textResumed, { name: file.name, percent: percentNow() })); wake?.(); };
    const onCancel = () => { cancelled = true; current?.abort(); wake?.(); };
    pause.addEventListener('click', onPause);
    resume.addEventListener('click', onResume);
    cancel.addEventListener('click', onCancel);
    const stopListening = () => {
      pause.removeEventListener('click', onPause);
      resume.removeEventListener('click', onResume);
      cancel.removeEventListener('click', onCancel);
    };
    const hideControls = () => {
      if (controls.contains(document.activeElement)) {
        status.tabIndex = -1;
        status.focus();
      }
      controls.hidden = true;
    };
    const reset = () => {
      stopListening();
      controls.hidden = true;
      bar.parentElement.hidden = true;
      bar.value = 0;
      if (button instanceof HTMLButtonElement) {
        button.disabled = false;
      }
      button?.focus();
    };
    const useServer = async () => {
      stopListening();
      await api('DELETE', `${data.direct}/${id}`).catch(() => null);
      forget(key);
      throw new UseServer();
    };

    controls.hidden = false;
    pause.hidden = false;
    resume.hidden = true;
    bar.parentElement.hidden = false;
    if (button instanceof HTMLButtonElement) {
      button.disabled = true;
    }

    if (!plan.validating) {
      show(done());
      let urls = {};
      let failures = 0;
      let sentAny = false;
      for (let number = 1; number <= plan.parts;) {
        if (cancelled) {
          await api('DELETE', `${data.direct}/${id}`).catch(() => null);
          forget(key);
          reset();
          status.textContent = say(data.textCancelled, { name: file.name });
          return;
        }
        if (paused) {
          await new Promise((resolve) => { wake = resolve; });
          urls = {}; // they may have expired during the pause
          continue;
        }
        if (received[number]) {
          number += 1;
          continue;
        }
        try {
          if (!urls[number]) {
            const wanted = [];
            for (let n = number; n <= plan.parts && wanted.length < plan.batch; n += 1) {
              if (!received[n]) {
                wanted.push(n);
              }
            }
            const signed = await api('POST', `${data.direct}/${id}/urls`, { parts: wanted });
            if (signed.status === 429) {
              await wait(Math.min(60, Number(signed.retryAfter) || 5) * 1000);
              continue;
            }
            if (signed.status !== 200) {
              throw new Error('refused');
            }
            urls = signed.json.urls;
          }
          const start = (number - 1) * plan.partBytes;
          const before = done();
          const answer = await putPart(urls[number], file.slice(start, start + plan.partBytes), (sent) => show(before + sent), (request) => { current = request; });
          current = null;
          if (answer.status === 200 && answer.etag) {
            received[number] = answer.etag.replaceAll('"', '');
            sentAny = true;
            failures = 0;
            show(done());
            number += 1;
            continue;
          }
          if (answer.status === 200 && !sentAny) {
            await useServer(); // the bucket hides the ETag from the browser: its CORS rules are incomplete
          }
          if (answer.status === 403) {
            urls = {};
          }
          throw new Error('refused');
        } catch (error) {
          current = null;
          if (error instanceof UseServer) {
            throw error;
          }
          if (error instanceof DOMException && error.name === 'AbortError') {
            continue;
          }
          if (!sentAny && Object.keys(received).length === 0) {
            await useServer(); // the browser wasn't allowed to reach the bucket at all
          }
          failures += 1;
          if (failures > RETRIES) {
            failures = 0;
            setPaused(true, data.textOffline);
            continue;
          }
          status.textContent = data.textRetrying;
          await wait(1000 * 2 ** (failures - 1));
        }
      }
      hideControls();
      status.textContent = say(data.textChecking, { name: file.name });
      for (let tries = 0; ; tries += 1) {
        const joined = await api('POST', `${data.direct}/${id}/complete`, { parts: received }).catch(() => null);
        if (joined?.status === 202) {
          break;
        }
        if (joined?.json?.location) {
          stopListening();
          forget(key);
          window.location.assign(joined.json.location);
          return;
        }
        if (tries >= RETRIES) {
          controls.hidden = false;
          setPaused(true, data.textOffline);
          await new Promise((resolve) => { wake = resolve; });
          hideControls();
          tries = 0;
        } else {
          await wait(1000 * 2 ** tries);
        }
      }
    } else {
      hideControls();
    }

    status.textContent = say(data.textChecking, { name: file.name });
    for (;;) {
      await wait(1500);
      const checked = await api('GET', `${data.direct}/${id}`).catch(() => null);
      if (checked?.json?.location) {
        stopListening();
        forget(key);
        window.location.assign(checked.json.location);
        return;
      }
    }
  };

  const sendLarge = (file) => {
    if (!data.direct) {
      return sendInParts(file);
    }

    return sendDirect(file).catch((error) => {
      if (!(error instanceof UseServer)) {
        throw error;
      }
      const started = sendInParts(file);
      status.textContent = data.textFallback;

      return started;
    });
  };

  const sendWhole = (file) => {
    const request = new XMLHttpRequest();
    let announced = -10;
    const announce = (percent) => {
      bar.value = percent;
      const step = Math.floor(percent / 10) * 10;
      if (step > announced) {
        announced = step;
        status.textContent = say(data.textProgress, { name: file.name, percent: step });
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
      status.textContent = say(data.textChecking, { name: file.name });
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
  };

  form.addEventListener('submit', (event) => {
    const file = input instanceof HTMLInputElement && input.files ? input.files[0] : null;
    if (!file) {
      return; // the server explains that a file is needed
    }
    event.preventDefault();
    if (chunkBytes > 0 && file.size > chunkBytes && controls && pause && resume && cancel) {
      sendLarge(file).catch(() => form.submit());
    } else {
      sendWhole(file);
    }
  });
})();
