/**
 * Akibara Selenium smoke tests.
 *
 * Browser engine redundancy: además de Playwright (Chromium-based via CDP),
 * Selenium WebDriver provee otra implementación independiente. Útil para
 * detectar bugs específicos del browser engine que Playwright pueda missear.
 *
 * READ-ONLY navigation. Cero clicks mutadores.
 *
 * Run: node tests/selenium/smoke.test.js
 */

const { Builder, By, until } = require('selenium-webdriver');
const chrome = require('selenium-webdriver/chrome');

const BASE_URL = process.env.SELENIUM_BASE_URL || 'https://akibara.cl';
const TIMEOUT = 15000;

const tests = [
  {
    name: 'Home loads + Akibara branding',
    url: '/',
    asserts: async (driver) => {
      const title = await driver.getTitle();
      if (!title.toLowerCase().includes('akibara')) {
        throw new Error(`Title no contiene Akibara: "${title}"`);
      }
      const body = await driver.findElement(By.css('body'));
      const text = await body.getText();
      if (text.toLowerCase().includes('error crítico')) {
        throw new Error('WSOD detected');
      }
      return 'OK';
    },
  },
  {
    name: 'Tienda page renders products',
    url: '/tienda/',
    asserts: async (driver) => {
      await driver.wait(until.elementsLocated(By.css('.product-card, .product, .wc-block-product, li.product')), TIMEOUT);
      const products = await driver.findElements(By.css('.product-card, .product, .wc-block-product, li.product'));
      if (products.length < 1) {
        throw new Error(`Productos esperados, encontrados: ${products.length}`);
      }
      return `OK (${products.length} productos)`;
    },
  },
  {
    name: 'Mi cuenta login form visible',
    url: '/mi-cuenta/',
    asserts: async (driver) => {
      await driver.wait(until.elementsLocated(By.css('input[name="username"], #username')), TIMEOUT);
      return 'OK';
    },
  },
  {
    name: 'Health endpoint REST',
    url: '/wp-json/akibara/v1/health',
    asserts: async (driver) => {
      const body = await driver.findElement(By.css('body'));
      const text = await body.getText();
      const json = JSON.parse(text);
      if (!json.status) {
        throw new Error(`Health status missing: ${text}`);
      }
      return `OK (status=${json.status})`;
    },
  },
];

(async function run() {
  const opts = new chrome.Options();
  opts.addArguments('--headless=new');
  opts.addArguments('--no-sandbox');
  opts.addArguments('--disable-dev-shm-usage');
  opts.addArguments('--window-size=1280,800');

  const driver = await new Builder().forBrowser('chrome').setChromeOptions(opts).build();

  let passed = 0;
  let failed = 0;
  const results = [];

  console.log(`\nAkibara Selenium smoke -- base: ${BASE_URL}\n`);

  for (const t of tests) {
    process.stdout.write(`  - ${t.name}... `);
    try {
      await driver.get(BASE_URL + t.url);
      const result = await t.asserts(driver);
      console.log(`PASS ${result}`);
      passed++;
      results.push({ name: t.name, status: 'pass', detail: result });
    } catch (err) {
      console.log(`FAIL ${err.message}`);
      failed++;
      results.push({ name: t.name, status: 'fail', detail: err.message });
    }
  }

  await driver.quit();

  console.log(`\nResultado: ${passed} passed, ${failed} failed (total ${tests.length})\n`);

  // Write JSON report
  const fs = require('fs');
  const path = require('path');
  const reportPath = path.join(__dirname, '../../test-results/selenium-smoke.json');
  fs.mkdirSync(path.dirname(reportPath), { recursive: true });
  fs.writeFileSync(reportPath, JSON.stringify({ passed, failed, total: tests.length, results, timestamp: new Date().toISOString() }, null, 2));

  process.exit(failed > 0 ? 1 : 0);
})();
