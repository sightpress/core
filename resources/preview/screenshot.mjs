// Screenshots of a theme's static preview page (theme:preview --screenshot): the top of the page (navbar and hero)
// at 1280×800 in light and dark mode, and the whole page in light mode, with reduced motion. Run by core's
// theme:preview; needs Node.js and Playwright's Chromium (the dev-tools image and CI have them; never a runtime
// dependency). Requests that would leave the folder are blocked and listed.
//
// Usage: node screenshot.mjs <folder with index.html>
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const folder = path.resolve(process.argv[2] ?? '.');
let chromium;
for (const base of [process.cwd() + '/', import.meta.url]) {
    try {
        ({ chromium } = createRequire(base)('playwright'));
        break;
    } catch {
        // try the next place
    }
}
if (!chromium) {
    console.error('Playwright is not installed here: run "npm install playwright" and "npx playwright install chromium".');
    process.exit(3);
}

let browser;
try {
    browser = await chromium.launch();
} catch (error) {
    console.error('Chromium could not start: ' + String(error.message ?? error).split('\n')[0]);
    process.exit(3);
}
const page = pathToFileURL(path.join(folder, 'index.html')).href;
const outside = new Set();
const shots = [
    ['screenshot.png', 'light', false],
    ['screenshot-dark.png', 'dark', false],
    ['page.png', 'light', true],
];
for (const [file, colorScheme, fullPage] of shots) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, colorScheme, reducedMotion: 'reduce' });
    await context.route('**/*', (route) => {
        const url = route.request().url();
        if (url.startsWith(pathToFileURL(folder).href + '/')) {
            return route.continue();
        }
        outside.add(url);
        return route.abort();
    });
    const tab = await context.newPage();
    await tab.goto(page, { waitUntil: 'load' });
    await tab.screenshot({ path: path.join(folder, file), fullPage, animations: 'disabled' });
    await context.close();
}
await browser.close();
for (const url of outside) {
    console.error('Blocked a request that would leave the folder: ' + url);
}
process.exit(outside.size === 0 ? 0 : 4);
