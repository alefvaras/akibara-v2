# B-S1-EMAIL-01 — Brevo SPF/DKIM/DMARC + sender domain validation

**Fecha kickoff:** 2026-04-27
**Status:** ⏳ PENDIENTE acción usuario en Brevo dashboard + Cloudflare DNS.
**Tiempo activo Claude:** 30 min (preparar este doc + verificar dig)
**Tiempo espera DNS propagation:** 1-24h
**Tiempo total cierre item:** 24-48h

---

## Estado actual DNS akibara.cl (verified `dig` 2026-04-27)

```
NS       : Cloudflare (elaine.ns.cloudflare.com, skip.ns.cloudflare.com) ✅
MX       : Hostinger (mx1.hostinger.com, mx2.hostinger.com)
SPF      : "v=spf1 include:_spf.mail.hostinger.com ~all"      ← FALTA Brevo include
DKIM     : (vacío — falta agregar brevo._domainkey)             ← FALTA agregar
DMARC    : "v=DMARC1; p=none; rua=mailto:rua@dmarc.brevo.com"  ← Existe con p=none, falta upgrade a p=quarantine
Brevo verify : "brevo-code:abf691cd58e7e69e6cac48c613d85566"   ← Ya agregado (Brevo verification token)
```

**Hallazgo importante:** SPF debe **mergearse** (no reemplazar) porque Hostinger MX está activo. Si reemplazas el SPF actual con solo el Brevo include, se rompen los emails que envíe Hostinger desde la cuenta `info@akibara.cl` u otras cuentas de correo.

---

## Acciones pendientes (en orden)

### Paso 1 — Brevo dashboard (UI manual)

URL: https://app.brevo.com/senders/list (login con la cuenta Akibara).

1. Ir a **Senders, Domains & Dedicated IPs** → tab **Domains** → buscar `akibara.cl`.
2. Si `akibara.cl` aparece como **Authenticated ✅** → saltar al Paso 2.
3. Si aparece como **Pending** o **Not authenticated**:
   - Click **Authenticate this domain**.
   - Brevo muestra 3 records (BREVO_VERIFICATION, BREVO_DKIM, BREVO_DMARC) con valores específicos.
   - Copiar los valores **literales** (especialmente el DKIM, que es largo y específico de la cuenta Akibara).
   - **NO** cerrar el panel Brevo hasta terminar Paso 2.

### Paso 2 — Cloudflare DNS (UI manual)

URL: https://dash.cloudflare.com/ → seleccionar zona `akibara.cl` → **DNS** → **Records**.

#### Record 1 — DKIM Brevo (AGREGAR — no existe)

| Campo | Valor |
|---|---|
| Type | TXT |
| Name | `brevo._domainkey` |
| TTL | 3600 (1 hour) |
| Proxy | DNS only (gris, NO naranja) |
| Content | `<copiar literal desde Brevo dashboard — empieza con "k=rsa; p=" seguido por la public key>` |

**Notas:**
- El valor del DKIM es específico de la cuenta Akibara — NO copiarlo de internet, copiarlo del panel Brevo (Senders → Domains → Authenticate → DKIM).
- Si el valor excede 255 caracteres, Cloudflare lo divide automáticamente en chunks (no necesitas hacer nada manual).
- Cloudflare tiene un campo **"Content"** simple — pegar todo el string sin las comillas exteriores (Cloudflare las agrega).

#### Record 2 — SPF (MODIFICAR el existente — NO reemplazar)

**Existente** (verificado 2026-04-27):

```
v=spf1 include:_spf.mail.hostinger.com ~all
```

**Nuevo** (agregando Brevo):

```
v=spf1 include:_spf.mail.hostinger.com include:spf.brevo.com ~all
```

| Campo | Valor |
|---|---|
| Type | TXT |
| Name | `@` (root, akibara.cl) |
| TTL | 3600 |
| Content | `v=spf1 include:_spf.mail.hostinger.com include:spf.brevo.com ~all` |

**Notas:**
- Editar el record SPF existente, NO crear uno nuevo. Tener 2 SPF records causa fallo silencioso en validación.
- Mantener `~all` (soft-fail) durante validación. Si en 30 días todo OK, considerar upgrade a `-all` (hard-fail) — decisión separada.

#### Record 3 — DMARC (MODIFICAR existente — endurecer policy)

**Existente** (verificado 2026-04-27):

```
v=DMARC1; p=none; rua=mailto:rua@dmarc.brevo.com
```

**Nuevo** (escalando a quarantine + agregar postmaster propio):

```
v=DMARC1; p=quarantine; rua=mailto:postmaster@akibara.cl,mailto:rua@dmarc.brevo.com; pct=100; adkim=r; aspf=r
```

| Campo | Valor |
|---|---|
| Type | TXT |
| Name | `_dmarc` |
| TTL | 3600 |
| Content | `v=DMARC1; p=quarantine; rua=mailto:postmaster@akibara.cl,mailto:rua@dmarc.brevo.com; pct=100; adkim=r; aspf=r` |

**Decisión `p=quarantine` vs `p=reject`:**
- Empezar con **quarantine** (4 semanas) → verificar reportes `rua` que no rebote correo legítimo Hostinger ni Brevo.
- Después de 4 semanas estable, considerar `p=reject` — decisión separada.
- `pct=100` empuja la policy a 100% del tráfico desde día 1 (no incremental). Si prefieres incremental, empezar con `pct=25` y escalar 50/75/100 cada semana.

**Decisión `postmaster@akibara.cl`:**
- El email `postmaster@akibara.cl` debe existir como alias/forwarding en Hostinger MX → Alejandro Vargas.
- Brevo recibe los reportes en su mailbox (`rua@dmarc.brevo.com`) — útil para visualizarlos en Brevo dashboard.
- Tener ambos da redundancia y permite que Alejandro reciba reportes brutos para auditoría manual.

#### Record 4 — Brevo verification token (YA EXISTE — verificar que sigue)

```
"brevo-code:abf691cd58e7e69e6cac48c613d85566"
```

Ya está. NO tocar. Si Brevo dashboard pide reverificar, regenerar el token y actualizar este record.

---

### Paso 3 — Verificación post-propagation (24h después)

Comandos `dig` para chequear que los 3 records propagaron OK. Ejecutar en local:

```bash
# 1. SPF actualizado con Brevo include
dig akibara.cl TXT +short | grep -i "brevo"
# Expect: "v=spf1 include:_spf.mail.hostinger.com include:spf.brevo.com ~all"

# 2. DKIM Brevo presente
dig brevo._domainkey.akibara.cl TXT +short
# Expect: una linea larga con "k=rsa; p=..."

# 3. DMARC con p=quarantine
dig _dmarc.akibara.cl TXT +short
# Expect: "v=DMARC1; p=quarantine; rua=mailto:postmaster@akibara.cl,mailto:rua@dmarc.brevo.com; ..."

# 4. Brevo dashboard verificación
# UI Brevo: Senders & IP → Domains → akibara.cl → status "Authenticated ✅"
```

Si los 3 dig devuelven los valores esperados → DNS propagation completa.

### Paso 4 — Smoke test cart abandonment Brevo upstream (24-48h después)

Una vez DNS validado y Brevo "Authenticated":

1. **En Brevo dashboard** → Automations → buscar workflow "Abandoned cart" o equivalente upstream → verificar status **Active**.
2. **Local Playwright (cuando exista smoke-prod.sh)** o manual:
   - Browser incógnito → https://akibara.cl/?p=24261 → Add to cart → ir al checkout → llenar email `alejandro.fvaras@gmail.com` → cerrar tab sin completar.
   - Esperar 1-3 horas (workflow Brevo upstream — el delay exacto depende de la config Brevo).
   - Revisar inbox `alejandro.fvaras@gmail.com` → expect email "olvidaste algo en tu carrito" o equivalente.
3. **Brevo dashboard** → Logs → buscar evento `cart_updated` con email `alejandro.fvaras@gmail.com` → status `delivered`.

**Si email NO llega en 24h y Brevo logs muestran `bounce` o `not delivered`:**
- Revisar headers Authentication-Results del último email recibido desde `noreply@akibara.cl` → buscar `dkim=pass`, `spf=pass`, `dmarc=pass`.
- Si `dkim=fail` → DKIM record copiado mal o falta sync.
- Si `spf=fail` → SPF mergeado incorrecto (probablemente 2 records en lugar de 1).

---

## Rollback (si algo se rompe)

SPF/DKIM/DMARC son records aditivos — el rollback es **revertir cambios en Cloudflare DNS** a los valores pre-2026-04-27:

```
SPF      : v=spf1 include:_spf.mail.hostinger.com ~all
DKIM     : (eliminar el record agregado)
DMARC    : v=DMARC1; p=none; rua=mailto:rua@dmarc.brevo.com
```

TTL 3600 = max 1h para que reverta.

**No se requiere snapshot ni backup DB para este item** (todo es DNS UI manual fuera de prod codebase).

---

## Notas para Claude / próxima sesión

- Este item se cierra solo después de:
  1. ✅ Sender domain Brevo: Authenticated.
  2. ✅ `dig` muestra los 3 records propagados.
  3. ✅ Smoke test cart abandonment delivered.
- Mientras tanto, no bloquea otros items del Sprint 1. SETUP-01 / SEC-02 / etc. avanzan en paralelo.
- Cuando se cierre, marcar `B-S1-EMAIL-01` como ✅ en BACKLOG y agregar fecha cierre + commit hash en commit message.
- Si surgen problemas durante validación: documentar en este mismo archivo (sección "Issues encontrados") + crear item follow-up.
