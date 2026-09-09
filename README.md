### MIKHMON V3

#### Configuration

Copy `include/config.example.php` to `include/config.php` before first use.
The generated `include/config.php` contains administrator and router
credentials and is intentionally excluded from Git.

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
