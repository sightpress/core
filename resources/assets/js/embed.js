// Embed blocks (BL-2): shows the "Show the video / map" button, and only when it is pressed puts the provider's
// frame in place of the notice, so nothing is loaded from the provider before the reader asks for it. Focus moves to
// the frame. Loaded as a module, so it runs once however many embeds the page has.
const show = (button) => {
  const figure = button.closest('[data-embed]');
  const consent = figure?.querySelector('[data-embed-consent]');
  const src = button.dataset.embedSrc ?? '';
  if (!consent || !src.startsWith('https://')) {
    return;
  }
  const frame = document.createElement('iframe');
  frame.src = src;
  frame.title = button.dataset.embedTitle ?? '';
  frame.className = 'block-embed__frame';
  frame.setAttribute('allow', 'fullscreen; picture-in-picture');
  frame.setAttribute('allowfullscreen', '');
  frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
  consent.replaceWith(frame);
  frame.focus();
};

for (const button of document.querySelectorAll('[data-embed-load]')) {
  button.hidden = false;
  button.addEventListener('click', () => show(button));
}
