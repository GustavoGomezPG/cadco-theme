/**
 * Exercises the pinned timeline at several milestone counts.
 * For each count it rebuilds the page, then drives the real page scroll and
 * asserts the pin length is derived from the content and the rail finishes
 * exactly as the pin releases.
 */
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const PB = '/Users/gustavogomez/.claude/plugins/cache/protoblocks/protoblocks-skill/2.0.0/skills/protoblocks-site-builder/scripts';
const T  = '/Users/gustavogomez/Local Sites/cadco/app/public/wp-content/themes/cadco-theme';
const IMG = {
  id: 18331,
  url: 'https://cadco.local/wp-content/uploads/2026/10/about-facility-scaled.jpg',
  alt: "Cadco's headquarters and manufacturing facility in Winsted, Connecticut, with the United States flag flying outside",
  caption: '', size: 'full',
};

const milestones = (n) =>
  Array.from({ length: n }, (_, i) => ({
    id: `m${i + 1}`,
    year: String(1996 + i * 3),
    title: i % 2 ? 'Expansion' : 'Milestone',
    body: 'Placeholder copy for a milestone entry in the Cadco history rail.',
    image: IMG,
  }));

const run = (args) => execFileSync('node', args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });

const counts = process.argv[2] ? process.argv[2].split(',').map(Number) : [4, 8, 20];
const results = [];

const browser = await chromium.launch();

for (const n of counts) {
  run([`${PB}/lib/state.mjs`, 'set', T, 'pages.1.sections.3.attrs.milestones', JSON.stringify(milestones(n))]);
  run([`${PB}/lib/page.mjs`, 'build', T, 'about']);

  const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e).slice(0, 120)));

  await page.goto('https://cadco.local/about/', { waitUntil: 'load' });
  await page.waitForTimeout(5500);

  const geom = await page.evaluate(() => {
    const s = document.querySelector('#pb-s4');
    const track = s.querySelector('[data-timeline-track]');
    const spacer = s.closest('.pin-spacer');
    const st = window.ScrollTrigger && window.ScrollTrigger.getAll().find((t) => t.trigger === s);
    return {
      slides: s.querySelectorAll('[data-timeline-item]').length,
      sectionH: Math.round(s.getBoundingClientRect().height),
      trackW: Math.round(track.scrollWidth),
      travel: Math.max(0, Math.round(track.scrollWidth - s.clientWidth)),
      pinned: !!spacer,
      spacerH: spacer ? Math.round(spacer.getBoundingClientRect().height) : null,
      stStart: st ? Math.round(st.start) : null,
      stEnd: st ? Math.round(st.end) : null,
      docW: document.documentElement.scrollWidth,
    };
  });

  // drive the page to the very end of the pinned range and read the rail there
  const atEnd = await page.evaluate(async () => {
    const s = document.querySelector('#pb-s4');
    const st = window.ScrollTrigger.getAll().find((t) => t.trigger === s);
    window.scrollTo(0, st.end + 2);
    await new Promise((r) => setTimeout(r, 1400));
    const track = s.querySelector('[data-timeline-track]');
    const last = s.querySelectorAll('[data-timeline-item]');
    const lastBox = last[last.length - 1].getBoundingClientRect();
    return {
      x: Math.round(new DOMMatrix(getComputedStyle(track).transform).m41),
      lastRight: Math.round(lastBox.right),
      viewportW: window.innerWidth,
    };
  });

  const expectedSpacer = geom.sectionH + geom.travel;
  results.push({
    n,
    slides: geom.slides,
    trackW: geom.trackW,
    travel: geom.travel,
    pinned: geom.pinned,
    spacerH: geom.spacerH,
    spacerExpected: expectedSpacer,
    spacerOk: geom.spacerH !== null && Math.abs(geom.spacerH - expectedSpacer) <= 6,
    pinRange: geom.stEnd !== null ? geom.stEnd - geom.stStart : null,
    pinRangeOk: geom.stEnd !== null && Math.abs((geom.stEnd - geom.stStart) - geom.travel) <= 6,
    endX: atEnd.x,
    railCompleted: Math.abs(atEnd.x + geom.travel) <= 6,
    lastVisible: atEnd.lastRight <= atEnd.viewportW + 6,
    noPageOverflow: geom.docW <= 1440,
    errors: errors.length ? errors : null,
  });

  await page.close();
}

await browser.close();
console.log(JSON.stringify(results, null, 1));
