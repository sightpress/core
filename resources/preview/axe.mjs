// Scans a theme's static preview page with axe (WCAG 2.2 AA) in light, dark and forced-colors mode, at 1280 px and
// 320 px, with requests that would leave the folder blocked. Run by core's theme:validate --axe; needs Node.js,
// Playwright's Chromium and @axe-core/playwright (tooling only, never a runtime dependency). Writes axe.txt to the
// folder and prints it; exits with 1 when axe finds a problem or a request tried to leave the folder.
//
// Usage: node axe.mjs <folder with index.html>
import { createRequire } from 'node:module';
import { writeFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const folder = path.resolve(process.argv[2] ?? '.');
let chromium;
let AxeBuilder;
for (const base of [process.cwd() + '/', import.meta.url]) {
    try {
        const require = createRequire(base);
        ({ chromium } = require('playwright'));
        AxeBuilder = require('@axe-core/playwright').default;
        break;
    } catch {
        // try the next place
    }
}
if (!chromium || !AxeBuilder) {
    console.error('Playwright and axe are not installed here: run "npm install playwright @axe-core/playwright" and "npx playwright install chromium".');
    process.exit(3);
}

let browser;
try {
    browser = await chromium.launch();
} catch (error) {
    console.error('Chromium could not start: ' + String(error.message ?? error).split('\n')[0]);
    process.exit(3);
}
const root = pathToFileURL(folder).href + '/';
const tags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const modes = { light: { colorScheme: 'light' }, dark: { colorScheme: 'dark' }, 'forced-colors': { forcedColors: 'active' } };
const report = [];
const outside = new Set();
let total = 0;
for (const [mode, media] of Object.entries(modes)) {
    for (const width of [1280, 320]) {
        const context = await browser.newContext({ viewport: { width, height: 800 } });
        await context.route('**/*', (route) => {
            const url = route.request().url();
            if (url.startsWith(root)) {
                return route.continue();
            }
            outside.add(url);
            return route.abort();
        });
        const page = await context.newPage();
        await page.emulateMedia(media);
        await page.goto(root + 'index.html');
        const { violations } = await new AxeBuilder({ page }).withTags(tags).analyze();
        total += violations.length;
        report.push(`${mode} at ${width} px: ${violations.length === 0 ? 'no violations' : violations.length + ' violation' + (violations.length === 1 ? '' : 's')}`);
        for (const v of violations) {
            report.push(`  - ${v.id} (${v.impact}): ${v.help}. ${v.helpUrl}`);
            for (const node of v.nodes) {
                report.push(`      ${node.target.join(' ')}: ${node.failureSummary.replace(/\s+/g, ' ')}`);
            }
        }
        await context.close();
    }
}
await browser.close();
for (const url of outside) {
    report.push(`Blocked a request that would leave the folder: ${url}`);
}
writeFileSync(path.join(folder, 'axe.txt'), report.join('\n') + '\n');
console.log(report.join('\n'));
process.exit(total > 0 || outside.size > 0 ? 1 : 0);
