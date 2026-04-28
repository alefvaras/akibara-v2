# Cleanup Legacy Modules Plugin Akibara — 2026-04-27

**Acción:** rm -rf de 17 modules migrated a addons en prod
**Backup:** ~/backups/2026-04-28-002225-pre-modules-cleanup.tar.gz (309KB)
**Smoke post-cleanup:** 6/6 HTTP 200 ✅
**Sentry post-cleanup:** 0 fatales

## 17 modules eliminados (1.4MB total)

| Module | Size | Migrated to |
|---|---|---|
| mercadolibre | 248K | akibara-mercadolibre |
| inventory | 64K | akibara-inventario |
| shipping | 120K | akibara-inventario |
| back-in-stock | 28K | akibara-inventario |
| brevo | 44K | akibara-marketing |
| banner | 36K | akibara-marketing |
| popup | 68K | akibara-marketing |
| descuentos | 120K | akibara-marketing |
| review-request | 48K | akibara-marketing |
| review-incentive | 24K | akibara-marketing |
| referrals | 72K | akibara-marketing |
| marketing-campaigns | 92K | akibara-marketing |
| finance-dashboard | 72K | akibara-marketing |
| cart-abandoned | 24K | akibara-marketing (DEPRECATED Brevo upstream) |
| welcome-discount | 108K | akibara-marketing |
| next-volume | 28K | akibara-preventas |
| series-notify | 32K | akibara-preventas |

## 10 modules MANTIENEN (NOT migrated — siguen activos en plugin akibara legacy)

- address-autocomplete (Google Places integration)
- checkout-validation
- customer-edit-address
- ga4 (archived per CLEAN-015 — pendiente mover a _archived/)
- health-check
- installments
- phone (Chile validator)
- product-badges
- rut (Chile validator)
- series-autofill (SEO Schema BookSeries)

## Skip pattern en akibara.php (mantener)

El hotfix #11 extended (commit 08725fc) sigue en akibara.php para defense-in-depth:
si por error se re-instala uno de estos modules, el skip detecta addon active y NO registra.

## Rollback (<5 min)

```bash
ssh akibara
cd ~/domains/akibara.cl/public_html/wp-content/plugins/akibara
tar -xzf ~/backups/2026-04-28-002225-pre-modules-cleanup.tar.gz
```
