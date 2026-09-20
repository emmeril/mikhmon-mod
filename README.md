### MIKHMON V3

#### Configuration

Copy `include/config.example.php` to `include/config.php` before first use.
The generated `include/config.php` contains administrator and router
credentials and is intentionally excluded from Git.

For the first login, use username `admin` and password `admin@123`. Create a
new administrator with a private password from `Settings > User Management`
before exposing the application to a public network.

Change the navigation brand from `Admin Settings > Brand Name`.

#### Production with PM2

Install PHP and PM2 on the server, then start Mikhmon from the project root:

```bash
pm2 start ecosystem.config.js --env production
pm2 save
```

The application listens on `0.0.0.0:80` by default. Set a different host
or port before starting PM2 when needed:

```bash
MIKHMON_HOST=127.0.0.1 MIKHMON_PORT=9000 pm2 start ecosystem.config.js --env production
```

The PM2 configuration enables OPcache for the long-running PHP process and
automatically uses up to 8 CPU workers (4 workers on a 4-core server). Override
the worker count or per-worker PHP memory limit when the server needs a
different balance:

```bash
MIKHMON_WORKERS=2 MIKHMON_PHP_MEMORY_LIMIT=192M \
  pm2 start ecosystem.config.js --env production
```

After changing these values, reload the process and persist the effective PM2
configuration:

```bash
pm2 restart ecosystem.config.js --env production --update-env
pm2 save
```

Keep PM2 access/error logs bounded with the native system log rotation (run
once for the account that owns the PM2 process):

```bash
sudo pm2 logrotate -u "$USER"
```

Run `pm2 startup` and follow the command it prints if the application should
start automatically after a server reboot.

#### Automatic daily backup

Mikhmon keeps a local, versioned backup of Hotspot and PPPoE users/profiles.
The web application backs up when a router session is accessed. For a backup
that runs even when nobody opens Mikhmon, add a daily system cron entry:

```cron
5 0 * * * /usr/bin/php /path/to/mikhmon/cron/backup.php >> /path/to/mikhmon/data/backup-cron.log 2>&1
```

Backup is one-way (`MikroTik -> local database`). Restore is always manual
from `Settings > Database Backup`; automatic recovery is intentionally disabled
so expired or intentionally deleted users are not recreated.

Router snapshots are retained for 7 days, based on their capture time. Older
snapshots (including an expired latest backup) are removed from the shared JSON
backup file when backups are read or written and at the start of the daily cron,
even if routers are offline. The active customer/invoice database is unaffected.

#### Customer database recovery from MikroTik

Open `Settings > Database Backup` to store an encrypted copy of the customer
database in MikroTik System Scripts. This copy includes customer identities,
phone numbers, addresses, service links, staff assignments, invoices, and local
report records for the selected router session.

Choose a recovery password of at least 8 characters and keep it outside both
Mikhmon and MikroTik. A fresh Mikhmon installation can connect to the router,
open the same page, and use `Pulihkan Database dari MikroTik` to merge the data
into its local database. The backup is split into inert `mikhmon-db-*` scripts,
encrypted before upload, and verified with a checksum. When automatic backup is
enabled, it is refreshed after the local database changes and the router is next
connected. Losing the recovery password makes the router copy unusable.

#### Automatic billing reminders and isolation

After enabling Billing Automation in `Settings > WhatsApp Gateway`, run the
worker every minute. The worker sends the configured reminder, isolates
unpaid services after the due date and retries failed Fonnte requests:

```cron
* * * * * /usr/bin/php /path/to/mikhmon/cron/billing.php >> /path/to/mikhmon/data/billing-cron.log 2>&1
```

The worker uses `MIKHMON_TIMEZONE` when set, otherwise `Asia/Jakarta`.
For the included Docker Compose setup, use `docker exec php_7_4 php
/var/www/cron/billing.php` as the cron command on the Docker host.
The first invoice for a customer is still created from Billing; subsequent
invoices are generated automatically after payment or from the last paid invoice.
Billing due dates use the 5th day of each month. A late payment does not move
the following billing cycle; the next invoice remains aligned to the monthly
5th.

Fonnte invoice, reminder, isolation, and payment messages are sent one recipient
at a time during working hours, from 07:00 to 17:00 in the configured Mikhmon
timezone. The next recipient gets a random 5-20 minute delay by default; this
range can be changed in `Settings > WhatsApp Gateway`. A slot that would pass
17:00 is moved to 07:00 on the next day. Messages pending outside those hours
are retried by the billing worker in the next working period. Manual invoice
sending follows the same working-hour restriction.

#### Payment gateway (Midtrans)

Open `Settings > Payment Gateway` as an administrator to enable online invoice
payments. Configure the Midtrans Sandbox/Production credentials, then save and
test the connection. An unpaid invoice in `Billing` can then generate a hosted
Midtrans payment link and store its reference on the invoice.

After a verified Midtrans payment, Mikhmon automatically attempts to
activate every customer service and completes the invoice. If the router is
offline, a user is missing, or one activation fails, the invoice remains in the
gateway-confirmed state and Billing shows `Coba Lagi Aktivasi`. Successful
activation is locked per invoice so duplicate callbacks cannot create duplicate
billing cycles.

Secrets can be supplied through environment variables instead of the settings
file: `MIKHMON_MIDTRANS_MERCHANT_ID`, `MIKHMON_MIDTRANS_SERVER_KEY`,
and `MIKHMON_MIDTRANS_CLIENT_KEY`. Environment values take precedence and are
never written back to disk. The local config is stored in
`data/payment-gateway.json` with restrictive permissions.

- Pembelian voucher hanya menampilkan profile hotspot dengan `Expired Mode` selain `None`; setelah pembayaran final webhook membuat username dan password voucher yang sama secara otomatis.
- Pembayaran langganan bulanan memakai layanan hotspot/PPPoE dengan `Expired Mode = None`; webhook menjalankan aktivasi ulang layanan yang terisolir melalui MikroTik.
- Riwayat pembayaran dan link invoice tersedia di dashboard pelanggan. Perubahan password hotspot berlaku untuk seluruh layanan hotspot milik pelanggan.
- Sesi login pelanggan bertahan 1 hari dan diperpanjang saat portal digunakan; logout akan langsung menghapus sesi sehingga OTP diperlukan lagi pada login berikutnya.
