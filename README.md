# Mi Club — Sistema de gestión AASR

Socios, cuotas y libro de caja de la Asociación Aeromodelista Santa Rosa. Laravel 12 + React 19 (Inertia) + PostgreSQL.

## Levantar el entorno de desarrollo

```bash
# 1. Postgres (una sola vez; queda con --restart unless-stopped)
docker run -d --name mi-club-pg --restart unless-stopped \
  -e POSTGRES_USER=mi_club -e POSTGRES_PASSWORD=mi_club -e POSTGRES_DB=mi_club \
  -p 5432:5432 -v mi_club_pgdata:/var/lib/postgresql/data postgres:17-alpine
docker exec mi-club-pg psql -U mi_club -c 'CREATE DATABASE mi_club_test'

# 2. App
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
composer dev            # servidor + cola + logs + vite
```

Requiere PHP 8.4 con `pdo_pgsql`, `gd`, `bcmath`, `intl`.

> **Abrí la app en `http://aasr.localhost:8000`, no en `127.0.0.1` ni `localhost`.** Las cookies no se separan por
> puerto: si tenés otro proyecto Laravel en `localhost`, su cookie `XSRF-TOKEN` pisa la de esta app y el login
> responde "Página expirada" (419). Con un hostname propio no hay colisión.

Usuario inicial (solo desarrollo): `tesorero@aasr.test` / `cambiar-esta-clave`. Se cambia con `TESORERO_EMAIL`,
`TESORERO_NOMBRE` y `TESORERO_PASSWORD` en `.env` antes de `php artisan db:seed`.

## Migración desde el Excel

```bash
php artisan socios:importar "Libro de Caja AASR.xlsx" --dry-run   # padrón: activos + bajas
php artisan socios:importar "Libro de Caja AASR.xlsx"

php artisan planes:sugerir  "Libro de Caja AASR.xlsx"             # genera storage/app/revision/*.csv
#   → revisar y completar revision-planes.csv (columna plan_final)
php artisan planes:asignar  storage/app/revision/revision-planes.csv --dry-run
php artisan planes:asignar  storage/app/revision/revision-planes.csv

php artisan socios:completar-desde-grilla "Libro de Caja AASR.xlsx" --dry-run
php artisan cuotas:devengar --desde=2026-01 --hasta=2026-09 --dry-run   # reconstruye 2026

php artisan libro:importar  "Libro de Caja AASR.xlsx" --dry-run   # movimientos, gastos, aperturas por cuenta, impuestos
php artisan cuotas:importar "Libro de Caja AASR.xlsx" --dry-run   # marca cuotas cobradas y vincula pagos con socios
#   → storage/app/revision/pagos-sin-vincular.csv: ingresos que el sistema no pudo emparejar con certeza
#   (los de meses anteriores a 2026 se ignoran a propósito: quedan solo en el libro)

php artisan pagos:vincular-incompleto <movimiento> <socio> --periodos=2026-02,2026-03
#   cuando lo que entró al banco es menos que lo que la planilla da por cobrado: manda el libro y la cuota queda incompleta
```

Orden de la migración completa: `socios:importar` → `planes:sugerir` / `planes:asignar` → `socios:completar-desde-grilla`
(da de alta a quienes pagan en la grilla y no están en el padrón, y registra apodos) → `cuotas:devengar --desde` →
`libro:importar` → `cuotas:importar` (repetible: no duplica nada). `libro:importar` solo corre sobre un libro vacío. Todos aceptan `--dry-run`.

`cuotas:devengar` también corre solo el día 1 de cada mes (`php artisan schedule:work` en dev, cron en producción).

## Bot de Telegram (Etapa 5)

El bot lee comprobantes de transferencia (foto o PDF) con IA vía OpenRouter, propone a qué socio y a qué
meses imputarlos, y no toca el libro de caja hasta que el tesorero confirma con un botón. También entiende
avisos de cobro en efectivo por texto ("Gabriel Gelos pagó 35000 en efectivo").

```bash
# .env (una vez)
TELEGRAM_BOT_TOKEN=...            # @BotFather
TELEGRAM_WEBHOOK_SECRET=...       # cualquier string al azar, lo valida el webhook
TELEGRAM_USUARIOS_AUTORIZADOS=usuario1,usuario2   # @usuarios de Telegram (sin @), se autorizan solos al escribir
OPENROUTER_API_KEY=...
AI_MODELO_VISION=anthropic/claude-sonnet-5
AI_MODELO_TEXTO=anthropic/claude-haiku-4.5
AI_MAX_COSTO_USD_MES=20           # tope de gasto mensual estimado; al superarlo el bot deja de llamar a la IA

# Desarrollo: exponer el webhook con ngrok y registrarlo
ngrok http 8000
php artisan telegram:webhook https://xxxx.ngrok.io   # hay que repetirlo cada vez que ngrok cambia de URL
```

Requiere el worker de colas corriendo (`composer dev` ya lo levanta): la extracción con IA se procesa en la
cola, nunca en el request del webhook. `php artisan schedule:work` expira (no borra) las ingestas sin
confirmar después de 7 días (`TELEGRAM_DIAS_EXPIRACION`).

Lo que no se resuelve solo (socio ambiguo, fecha ilegible, un error) queda en **Bandeja de Telegram** en el
panel web. Comandos del bot: `/deudores`, `/socio <nombre>`, `/saldo`, `/pendientes`.

## Impuestos del libro de caja

SIRCREB, impuesto al crédito y al débito se calculan **por día y por cuenta bancaria** y se recalculan solos al cargar,
re-fechar o anular un movimiento. El recálculo corre en la cola, así que **necesita un worker**
(`composer dev` ya lo levanta; en producción, `php artisan queue:work` bajo supervisor/systemd).

`AASR_CORTE_TRIBUTOS` (decisión D2) es la fecha desde la cual el recálculo automático manda; los días anteriores vienen
del Excel y no se reescriben. En desarrollo está vacío (sin corte) para poder probar; en `.env.example` es `2026-10-01`.

## Calidad

```bash
vendor/bin/pest && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G
npm run types && npm run format:check
```

## Reglas de negocio que conviene no romper

- Importes en `decimal(14,2)` y `brick/math`; nunca `float`.
- Las tarifas no se editan: se sucede una nueva y se cierra la anterior.
- Una cuota impaga se cobra a la tarifa vigente el día del pago; una cobrada queda congelada.
- Nada se borra: bajas y anulaciones dejan historial.
