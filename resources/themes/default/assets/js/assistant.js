// The AI assistant panel (A-3). The forms work without this script (they go to a page with the answer); with it:
// - "Ask" and the Suggest buttons send their form in the background. "Working on it…" and "Suggestion ready" go to
//   the panel's polite live region (#assistant-status) and errors to its alert region (#assistant-error). The
//   suggestion goes under its own heading in #assistant-result, never into a live region, and focus moves to that
//   heading;
// - "Insert" puts the suggestion in the field the Suggest button named (data-assistant-target), and in the hidden
//   copy it names (data-assistant-suggestion, for alt text that stays marked as the assistant's), and moves focus
//   back to it; a draft's "Add to the end of the page" sends it to the server, which adds its blocks;
// - "Explain this" next to each publish check asks in the same way; "Copy" copies it; "Try again" asks again; "Discard" removes it and moves focus back to where the person
//   asked;
// - "Test connection" on the AI assistant screen shows its result in the live region named by data-assistant-test.
// Texts come from the panel's data attributes, in the person's language.
(() => {
  'use strict';

  const panel = document.getElementById('assistant-panel');
  const status = document.getElementById('assistant-status');
  const errors = document.getElementById('assistant-error');
  const result = document.getElementById('assistant-result');
  const text = (name, fallback) => panel?.dataset[name] ?? fallback;

  // A live region only speaks when its text changes: empty it first, so the same words are read again.
  const say = (region, words) => {
    if (!region) {
      return;
    }
    region.textContent = '';
    window.setTimeout(() => {
      region.textContent = words;
    }, 50);
  };

  const send = async (form, data) => {
    const response = await fetch(form.action, {
      method: 'POST',
      body: data,
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
      credentials: 'same-origin',
    });
    return response.json();
  };

  const button = (label, className, onClick) => {
    const element = document.createElement('button');
    element.type = 'button';
    element.className = className;
    element.textContent = label;
    element.addEventListener('click', onClick);
    return element;
  };

  // A draft's blocks go into the page through the server, like Insert without JavaScript.
  const post = (form, insert, answer) => {
    const sent = document.createElement('form');
    sent.method = 'post';
    sent.action = '/admin/assistant/insert';
    sent.hidden = true;
    const values = { field: insert.field, text: insert.text, return: answer.return ?? '' };
    form.querySelectorAll('input[name^="csrf_"], input[name="return"]').forEach((input) => {
      values[input.name] = input.value;
    });
    Object.entries(values).forEach(([name, value]) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      sent.append(input);
    });
    document.body.append(sent);
    sent.submit();
  };

  const show = (answer, form, data, origin, target) => {
    result.replaceChildren();
    const section = document.createElement('section');
    section.className = 'assistant-suggestion';
    section.setAttribute('aria-labelledby', 'assistant-result-title');
    const heading = document.createElement('h3');
    heading.id = 'assistant-result-title';
    heading.tabIndex = -1;
    heading.textContent = answer.heading;
    const body = document.createElement('div');
    body.className = 'assistant-suggestion__text';
    if (answer.lang) {
      body.lang = answer.lang;
    }
    answer.text.split(/\n{2,}/).forEach((paragraph) => {
      const p = document.createElement('p');
      p.textContent = paragraph;
      body.append(p);
    });
    section.append(heading, body);
    (answer.notes ?? []).forEach((note) => {
      const p = document.createElement('p');
      p.className = 'field-hint';
      p.textContent = note;
      section.append(p);
    });

    const actions = document.createElement('div');
    actions.className = 'assistant-actions';
    const field = target ? document.getElementById(target) : null;
    if (answer.insert && answer.insert.field === 'blocks') {
      actions.append(button(text('addBlocks', 'Add to the end of the page'), '', () => post(form, answer.insert, answer)));
    } else if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
      actions.append(button(text('insert', 'Insert'), '', () => {
        const kept = document.getElementById(origin?.dataset.assistantSuggestion ?? '');
        if (kept instanceof HTMLInputElement) {
          kept.value = answer.text;
        }
        field.value = answer.text;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        result.replaceChildren();
        say(status, text('inserted', 'Inserted.'));
        field.focus();
      }));
    }
    if (navigator.clipboard) {
      actions.append(button(text('copy', 'Copy'), 'button button--secondary', () => {
        navigator.clipboard.writeText(answer.text).then(() => say(status, text('copied', 'Copied.')), () => {});
      }));
    }
    actions.append(button(text('retry', 'Try again'), 'button button--secondary', () => ask(form, data, origin, target)));
    actions.append(button(text('discard', 'Discard'), 'button button--secondary', () => {
      result.replaceChildren();
      say(status, text('discarded', 'Discarded.'));
      origin?.focus();
    }));
    section.append(actions);
    result.append(section);
    say(status, answer.status);
    heading.focus();
  };

  const ask = async (form, data, origin, target) => {
    if (errors) {
      errors.textContent = '';
    }
    result?.replaceChildren();
    say(status, text('working', 'Working on it…'));
    let answer = null;
    try {
      answer = await send(form, data);
    } catch {
      answer = null;
    }
    if (answer && answer.ok === true && typeof answer.text === 'string') {
      show(answer, form, data, origin, target);
      return;
    }
    if (status) {
      status.textContent = '';
    }
    if (errors) {
      errors.textContent = answer && typeof answer.error === 'string' ? answer.error : text('failed', 'The assistant couldn’t be reached. Try again.');
    }
  };

  if (panel && status && result && window.fetch && window.FormData) {
    document.querySelectorAll('form[data-assistant-ask]').forEach((form) => {
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        const submitter = event.submitter instanceof HTMLButtonElement ? event.submitter : null;
        const data = new FormData(form, submitter);
        const origin = submitter ?? form.querySelector('textarea, button');
        ask(form, data, origin, submitter?.dataset.assistantTarget ?? null);
      });
    });
  }

  document.querySelectorAll('form[data-assistant-test]').forEach((form) => {
    const region = document.getElementById(form.dataset.assistantTest ?? '');
    if (!(form instanceof HTMLFormElement) || !region || !window.fetch || !window.FormData) {
      return;
    }
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      say(region, form.dataset.working ?? '');
      try {
        const answer = await send(form, new FormData(form));
        say(region, typeof answer.message === 'string' ? answer.message : '');
      } catch {
        form.submit();
      }
    });
  });
})();
