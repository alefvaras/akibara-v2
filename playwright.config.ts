import { defineConfig, devices } from '@playwright/test';

/**
 * Akibara E2E config — Sprint 2 B-S2-SETUP-01.
 *
 * Filosofía:
 * - Solo @critical golden flow tests por default (Akibara = 3 customers, no full coverage).
 * - PLAYWRIGHT_BASE_URL env var: prod por default, staging cuando se necesite.
 * - Mobile-first: viewport principal 375px (iPhone 12 viewport approx).
 * - Sin browser cross matrix excepto LambdaTest visual sprint X.5.
 *
 * Memorias relevantes:
 * - feedback_no_over_engineering.md (no full E2E coverage)
 * - project_qa_lambdatest_policy.md (LambdaTest reservado visual sprint X.5)
 * - project_quality_gates_stack.md (Playwright @critical en CI)
 *
 * Uso:
 *   npm run test:e2e:critical              # corre @critical contra prod
 *   PLAYWRIGHT_BASE_URL=https://staging.akibara.cl \
 *     PLAYWRIGHT_BASIC_AUTH=alejandro:<pass> \
 *     npm run test:e2e:critical            # contra staging con basic auth
 *   npm run test:e2e:headed                # ver browser real (debug local)
 */

const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'https://akibara.cl';
const BASIC_AUTH = process.env.PLAYWRIGHT_BASIC_AUTH; // formato user:pass — solo staging

// httpCredentials para staging basic-auth scenario (parseado de env)
const httpCredentials = (() => {
  if (!BASIC_AUTH) return undefined;
  const [username, ...rest] = BASIC_AUTH.split(':');
  return { username, password: rest.join(':') };
})();

export default defineConfig({
  testDir: './tests/e2e',
  // Sequential vs prod URLs para evitar 429 rate-limit
  // (Sprint 1 B-S1-SEC-07 rate-limited /cart/* a 10/min).
  // Para staging tests con basic auth, podríamos paralelizar.
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1, // sequential — 1 worker en CI y local
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',

  use: {
    baseURL: BASE_URL,
    httpCredentials,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    locale: 'es-CL',
    timezoneId: 'America/Santiago',
  },

  projects: [
    // Solo mobile project (mayoría tráfico Akibara). Desktop coverage
    // reservado para LambdaTest visual regression sprint X.5
    // (memoria project_qa_lambdatest_policy).
    {
      name: 'mobile',
      use: { ...devices['iPhone 12'] },
      testIgnore: ['**/admin/**'],
    },

    // ── Admin QA project (Sprint 5.5) ────────────────────────────────────────
    // Read-only admin testing. Login una vez vía auth.setup, reusa storageState.
    // User explicit "no clicklea" — solo navega + assert layout/200.
    // Activa solo si WP_ADMIN_USER + WP_ADMIN_PASSWORD env vars.
    {
      name: 'admin-setup',
      testMatch: /admin\/auth\.setup\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'admin',
      testMatch: /admin\/.*\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
      dependencies: ['admin-setup'],
    },
  ],

  expect: {
    timeout: 10_000,
  },
  timeout: 60_000,
});
