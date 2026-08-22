# Deploying to Lightsail

This site runs on its own AWS Lightsail instance with its own MySQL database.
It shares nothing with monthausint.com but the Spark API credentials.

Three parts: get SSH working (and Nova publishing with it), stand up the
database, then put the files in place.

---

## Part 1 — SSH access and Nova publishing

### Attach a static IP first

Lightsail instances get a new public IP every time they stop and start.
Attach a static IP before you configure anything that points at the box, or
you will be re-entering the address in Nova and re-issuing your TLS
certificate later.

**Lightsail console → Networking → Create static IP → attach to the instance.**
It is free while attached to a running instance.

### Get the key

Lightsail gives every region a default key pair:

**Console → click your account name (top right) → Account → SSH keys →
Default keys → Download** the private key for your region.

That works, but the default key is RSA, is shared by every instance in the
region, and AWS lets you download it exactly once. For a box that will hold
database credentials and an OAuth client secret, generate your own instead:

```bash
ssh-keygen -t ed25519 -C "marketing.monthaus.com" -f ~/.ssh/mh_marketing
```

Then **Account → SSH keys → Upload new key pair**, or paste
`~/.ssh/mh_marketing.pub` into the instance's authorized keys on first
connect. Give it a passphrase — Nova and macOS Keychain will remember it.

Whichever key you use, lock down the file or SSH will refuse it:

```bash
chmod 400 ~/.ssh/mh_marketing        # or the downloaded .pem
```

### Know your username

There is no single answer — it depends on the blueprint you launched:

| Blueprint | Username |
|---|---|
| **LAMP (this one)** — Debian based | **`admin`** |
| Debian | `admin` |
| Ubuntu | `ubuntu` |
| Amazon Linux 2 / 2023 | `ec2-user` |

AWS retired the Bitnami blueprints; the current LAMP blueprint is their own,
on Debian, with a standard Apache layout. Older tutorials that tell you to use
`bitnami` and `/opt/bitnami/...` do not apply here.

Confirm with a plain terminal connection before involving Nova:

```bash
ssh -i ~/.ssh/mh_marketing admin@<static-ip>
```

If that hangs, open the firewall: **instance → Networking → IPv4 Firewall →
SSH / TCP / 22**. Restrict the source to your own IP rather than leaving it
open to the world.

### Configure Nova

Nova does **not** read `~/.ssh` automatically — keys are imported into the
app.

1. **Nova → Settings → Keys → +** — import `~/.ssh/mh_marketing`, or generate
   a fresh pair here and upload the public half to Lightsail. Nova handles
   PEM (`.pem`) and OpenSSH formats, RSA and Ed25519 alike.
2. **Nova → Settings → Servers → +**, protocol **SFTP**:

   | Field | Value |
   |---|---|
   | Server | your static IP |
   | Port | 22 |
   | Username | `admin` (or per the table above) |
   | Password | leave blank |
   | Key | click the key icon and pick the one you imported |
   | Remote Path | the web root — see below |

3. Click **Validate**. Fix it here rather than discovering the problem
   mid-publish.
4. In the project, set that server as the publish target. Nova's **Publish**
   then uploads relative to Remote Path.

### Web root, and the permission trap

The LAMP blueprint serves from `/var/www/html` and manages Apache with
`systemctl` — standard Debian, nothing Bitnami-shaped.

That directory is owned by `root`, and your SSH user is not. Publishing
will fail with permission errors, and the usual reflex — `chmod 777` — leaves
the site world-writable.

Give your deploy user ownership instead:

```bash
sudo mkdir -p /var/www/marketing.monthaus.com
sudo chown -R admin:www-data /var/www/marketing.monthaus.com
sudo find /var/www/marketing.monthaus.com -type d -exec chmod 2775 {} \;
sudo find /var/www/marketing.monthaus.com -type f -exec chmod 0664 {} \;
```

`2775` sets the setgid bit, so files Nova uploads keep the `www-data` group
and Apache can read them without another chmod. Point the Apache vhost's
`DocumentRoot` at that directory.

### Apache: two vhosts can claim the same hostname

Check this **first**, before writing any vhost:

```bash
sudo apache2ctl -S
```

On this instance, `000-default.conf` already had
`ServerName marketing.monthaus.com` and a Certbot HTTPS-redirect block — put
there when the Lightsail instance was first set up. Apache loads sites
alphabetically and uses the **first** name match, so `000-default.conf` beat
`marketing.monthaus.com.conf` on every request and the new vhost never ran at
all.

The symptom is confusing: requests return 301 to HTTPS, HTTPS serves Debian's
default page, and security rules in the new vhost appear to do nothing —
because none of it is being consulted. `apache2ctl -S` shows it immediately:

```
port 80 namevhost marketing.monthaus.com (000-default.conf:1)          <- wins
port 80 namevhost marketing.monthaus.com (marketing.monthaus.com.conf:1)
```

Fix by disabling the defaults so only one vhost owns the name:

```bash
sudo a2dissite 000-default default-ssl
sudo apache2ctl configtest && sudo systemctl reload apache2
sudo apache2ctl -S            # confirm only our file is listed
```

### DNS and TLS may already be done

Setting up the Lightsail instance had already pointed DNS at the static IP and
run Certbot, leaving a valid Let's Encrypt certificate for the domain. Check
before issuing another — Let's Encrypt rate-limits duplicates:

```bash
sudo certbot certificates
```

`deploy/marketing.monthaus.com.conf` reuses those cert paths rather than
reissuing. After moving the vhost Certbot was managing, confirm renewal still
works — a silently broken renewal is an outage roughly 60 days later:

```bash
sudo certbot renew --dry-run
```

### Apache: the AllowOverride trap

Debian's `apache2.conf` on the LAMP blueprint sets:

```
<Directory /var/www/>          AllowOverride None     <- line ~177
<Directory "/var/www/html">    AllowOverride All      <- line ~205
```

`All` covers **only** the default site. A docroot at
`/var/www/marketing.monthaus.com` inherits `None` from the `/var/www/` block,
which means **`.htaccess` is silently ignored** — and `db.php` and
`sso_config.php` get served as plain text. Database credentials and the OAuth
client secret, readable by anyone who requests the filename, with no error
anywhere to warn you.

So the vhost must set `AllowOverride All` on its own directory, and it also
repeats the critical denies at config level so they do not depend on
`.htaccess` working at all.

```bash
sudo tee /etc/apache2/sites-available/marketing.monthaus.com.conf > /dev/null <<'VHOST'
<VirtualHost *:80>
    ServerName marketing.monthaus.com
    DocumentRoot /var/www/marketing.monthaus.com

    <Directory /var/www/marketing.monthaus.com>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch "^(db|db\.sample|auth|sso|sso_config|sso_config\.sample|config|mailer|_footer|_nav|_onboarding)\.php$">
        Require all denied
    </FilesMatch>

    <FilesMatch "\.(sql|log|md|json|lock|py|bak|old|orig|swp|env)$">
        Require all denied
    </FilesMatch>

    <DirectoryMatch "^/var/www/marketing\.monthaus\.com/(sql|tests|vendor|cron)(/|$)">
        Require all denied
    </DirectoryMatch>

    ErrorLog  ${APACHE_LOG_DIR}/marketing_error.log
    CustomLog ${APACHE_LOG_DIR}/marketing_access.log combined
</VirtualHost>
VHOST

sudo a2ensite marketing.monthaus.com
sudo a2enmod headers rewrite
sudo apache2ctl configtest && sudo systemctl reload apache2
```

**Verify it before publishing anything real:**

```bash
echo '<?php define("DB_PASS","should-never-be-visible");' | sudo tee /var/www/marketing.monthaus.com/inc/db.php
curl -s -o /dev/null -w "%{http_code}\n" https://marketing.monthaus.com/inc/db.php
sudo rm /var/www/marketing.monthaus.com/inc/db.php
```

**403 is the only acceptable answer.** A 200 means credentials are being
served to the web.

Do not test this over plain HTTP against `localhost` with a `Host:` header —
the `:80` vhost redirects to HTTPS, so you get a 301 and learn nothing about
whether the deny rules work.

### PHP: check which version Apache actually runs

The blueprint ships many PHP versions (5.6 through 8.5 on this box) and the
Apache module and the CLI default can differ — here Apache loaded 8.4 while
`php` on the command line was 8.5. Web pages and cron scripts would then run
different interpreters, which is a fine way to get a bug that reproduces in
only one of them.

```bash
ls -l /etc/apache2/mods-enabled/php*        # which version Apache loads
sudo update-alternatives --set php /usr/bin/php8.4   # match the CLI to it
```

Install extensions with the **version-specific** package names. `apt install
php-curl` installs for Debian's default version, which may not be the one you
are running:

```bash
sudo apt install -y php8.4-curl php8.4-mbstring php8.4-gd
php -m | grep -iE "curl|mbstring|gd|mysqli|openssl"
```

`curl` and `openssl` are not optional — `sso.php` does the token exchange and
the JWKS fetch with them, so Microsoft sign-in cannot work without both.

### phpMyAdmin is already installed

At `/var/www/html/phpmyadmin`, with `Require local` in `apache2.conf` — so it
is reachable only from the instance itself, not the internet. Leave that as
it is. To use it, tunnel:

```bash
ssh -i ~/.ssh/mh_marketing -L 8080:localhost:80 admin@<static-ip> -N
# then browse http://localhost:8080/phpmyadmin
```

It points at the local MariaDB, not the managed database, so it needs
reconfiguring before it is useful here. TablePlus over an SSH tunnel does the
same job with less setup.

### Two directories outside the web root

```bash
sudo mkdir -p /var/www/marketing.monthaus.com/../receipts   # RECEIPTS_DIR
sudo mkdir -p /var/log/mh-marketing                         # cron logs
sudo chown www-data:www-data /var/www/receipts
sudo chown admin:admin /var/log/mh-marketing
```

`receipts/` must be writable by Apache — `agent.php` creates files there and
`receipt.php` streams them back after an auth check. It must **not** be
inside the web root; the whole point is that receipts carry vendor and cost
detail and can never be fetched by guessing a URL.

---

## Part 2 — The database

The database is a **Lightsail managed MySQL 8.4 instance**, not MySQL on this
box. With 1 GB of RAM, running Apache, PHP and MySQL together on the instance
would be tight enough to invite OOM kills under load, which is what the $15/mo
buys you out of — along with automatic backups.

The LAMP blueprint ships a local **MariaDB 10.11**, running by default. Stop
and disable it — it holds memory you are paying a managed database not to need:

```bash
sudo systemctl stop mariadb
sudo systemctl disable mariadb
free -h
```

MariaDB is also why running the database locally was never an option here, and
this was verified rather than assumed: MariaDB 10.11 has no
`utf8mb4_0900_ai_ci` collation, and `bootstrap.sql` fails on its first
`CREATE TABLE`, producing zero tables. Using it would have meant installing
Oracle MySQL 8 by hand or rewriting every collation in the schema.

### Client and CA bundle

The LAMP blueprint already ships a client — but it is **MariaDB's**, not
Oracle's, and the two differ in ways that matter here:

- The TLS flag is `--ssl`, not MySQL's `--ssl-mode=REQUIRED`. Passing the
  latter gives `unknown variable 'ssl-mode=REQUIRED'`.
- Do **not** `apt install mysql-client` — on Debian that package is an alias
  for `mariadb-client` and simply reinstalls what you already have.

Tested against this database: the MariaDB client authenticates against MySQL
8.4 without trouble, despite the common warning that it cannot handle
`caching_sha2_password`. No Oracle client needed. If a future MySQL release
does break it, install `mysql-community-client` from repo.mysql.com alongside;
it does not disturb PHP or Apache.

You do need AWS's CA bundle so connections can be verified:

```bash
sudo mkdir -p /etc/ssl/aws
sudo curl -o /etc/ssl/aws/rds-global-bundle.pem \
     https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem
sudo chmod 644 /etc/ssl/aws/rds-global-bundle.pem
```

### Lock the database down

In the Lightsail console: **Databases → your database → Networking → public
mode OFF**. It should be reachable only from inside the VPC. Confirm the
database and the instance are both in `us-west-2`.

### Connect and verify

Set the endpoint once so the rest of the commands stay short. The endpoint is
on the database's **Connect** tab in the console, along with the master
username and password.

```bash
DBH=ls-xxxxxxxx.xxxxxxxx.us-west-2.rds.amazonaws.com

mysql -h $DBH -u <master-user> -p --ssl -e "SELECT VERSION(), @@collation_server;"
```

`ERROR 1045 (28000): Access denied` here means the username or password is
wrong, not that anything is misconfigured — check both against the Connect
tab and copy the password rather than retyping it.

Expect `8.4.x` and `utf8mb4_0900_ai_ci` — the same major version as the hub
export, which is why the schema imports without translation.

### Load the schema and data

Three files, in this order, against `dbmarketing_monthaus`:

Copy them up from your Mac first:

```bash
scp -i ~/.ssh/mh_marketing sql/bootstrap.sql sql/assets_tab_v2.sql sql/sso_schema.sql \
    admin@<static-ip>:~/
```

Then, on the instance:

```bash
mysql -h $DBH -u <master-user> -p --ssl dbmarketing_monthaus < bootstrap.sql
mysql -h $DBH -u <master-user> -p --ssl dbmarketing_monthaus < assets_tab_v2.sql
mysql -h $DBH -u <master-user> -p --ssl dbmarketing_monthaus < sso_schema.sql
```

Order matters: `assets_tab_v2.sql` references `marketing_intakes` and
`sso_schema.sql` alters `users`, so `bootstrap.sql` has to land first.
`mysql` prints nothing on a successful import — silence is success.

`bootstrap.sql` holds the 13 tables this app actually uses, carved out of the
hub's 33 and normalised onto one collation. It was verified by importing it
into a clean MySQL 8 and then running every page of the app against it.

`assets_tab_v2.sql` creates `marketing_asset_links`. Worth knowing: that
migration was **never run on the hub**. `agent.php` catches the resulting
error and logs `marketing_asset_links not available`, so the link list on the
Assets & Docs tab has been silently doing nothing there. Running it here
turns the feature on.

### Create the app's own user

The master account can DROP and ALTER. `db.php` is read by every web request
and should not hold credentials that can destroy anything:

```sql
CREATE USER 'mh_app'@'%' IDENTIFIED BY '<a long random password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON dbmarketing_monthaus.* TO 'mh_app'@'%';
FLUSH PRIVILEGES;
```

`'@%'` rather than `'@localhost'` because the app now connects over the
network. That wildcard is bounded by the VPC, which is why public mode being
off matters.

Then set the instance clock so cron output and page timestamps agree:

```bash
sudo timedatectl set-timezone UTC
```

### Verify

Expected on a correct import: **14 tables**, one collation, 17 users,
24 agents, 144 tasks.

```sql
-- one row, one collation
SELECT DISTINCT TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES
 WHERE TABLE_SCHEMA = 'dbmarketing_monthaus';

-- 14 tables
SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
 WHERE TABLE_SCHEMA = 'dbmarketing_monthaus';

-- at least one break-glass account
SELECT email FROM users
 WHERE role='super_admin' AND password IS NOT NULL AND password <> '';
```

### The sql_mode gotcha — read this one

The roster queries in `index.php` and `roster.php` select non-aggregated
columns alongside a `GROUP BY`. SiteGround ran MySQL with
`ONLY_FULL_GROUP_BY` switched off, so they have worked there for months.
Stock MySQL 8 enables it by default and **both pages return a 500 on the
first request**.

`db.sample.php` handles this by setting `sql_mode` per connection. Keep that
line when you create `db.php`. Setting it in a parameter group instead would
work until the next restored snapshot quietly reset it.

### On caching_sha2_password

MySQL 8.4 defaults to the `caching_sha2_password` plugin, and a lot of
tutorials will tell you PHP cannot handle it without TLS. Tested: PHP 8's
mysqlnd connects to it over a plain socket without complaint. So an
unencrypted connection will *appear* to work fine — which is precisely why
`db.sample.php` requests TLS explicitly. The reason to encrypt is that
credentials and row data now leave the instance, not that the connection
would otherwise fail.

---

## Part 3 — Files and cron

Publish everything except `inc/db.php` and `inc/sso_config.php` — neither
exists locally, which is the point. Create them on the server, **in `inc/`**,
from the samples in `deploy/`:

```bash
cd /var/www/marketing.monthaus.com
cp deploy/db.sample.php        inc/db.php
cp deploy/sso_config.sample.php inc/sso_config.php
nano inc/db.php                 # fill in credentials
chmod 640 inc/db.php inc/sso_config.php
sudo chown admin:www-data inc/db.php inc/sso_config.php
```

Confirm the whole directory is unreachable:

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://marketing.monthaus.com/inc/db.php
curl -s -o /dev/null -w "%{http_code}\n" https://marketing.monthaus.com/inc/sso_config.php
```

Both must be **403 or 404**, never 200. A blank page instead means `.htaccess` is not being
read: Apache needs `AllowOverride All` on the directory, which is *not* the
Ubuntu default.

Enable the modules `.htaccess` relies on:

```bash
sudo a2enmod rewrite headers && sudo systemctl restart apache2
```

### TLS

The Entra redirect URI must be `https`, so this is required, not optional:

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d marketing.monthaus.com
```

Point the DNS A record at the static IP first.

### Cron

Two jobs keep the MLS-derived tables current. They pull from the Spark API,
not from the hub, which is what makes this site genuinely independent.

```cron
# office_roster — daily at 03:00 UTC
0 3 * * * /usr/bin/php /var/www/marketing.monthaus.com/cron/sync_roster.php >> /var/log/mh-marketing/sync_roster.log 2>&1

# mh_brokers — daily at 03:30 UTC
30 3 * * * /usr/bin/php /var/www/marketing.monthaus.com/cron/sync_mh_brokers.php >> /var/log/mh-marketing/sync_mh_brokers.log 2>&1
```

Both scripts include `__DIR__ . '/../inc/db.php'`, so they pick up the same
credentials and the same `sql_mode` the web app uses.

Install with `crontab -e` as the `admin` user. Run each once by hand first —
they print a summary, and a silent run means something is wrong.

`php -l <file>` is the fastest way to rule out syntax when a CLI script
produces no output at all. An empty log means the script never ran, not that
it ran and found nothing.

---

## What is no longer shared

Worth being explicit, because it is the thing most likely to surprise someone
later:

- **`users` is a separate table now.** An account created on the hub does not
  appear here, and vice versa. 17 accounts came across in the bootstrap.
  With Entra as the identity source, this table is an authorization list —
  who may sign in and with what role — rather than a credential store.
- **`office_roster` MLS key edits made in `roster.php` stay here.** The hub
  keeps its own copy, refreshed from Spark by its own cron. Manual key
  linking done on one site does not reach the other.
- **`marketing_intakes.roster_id` is linked by this site's own cron.** The
  hub's `sync_roster.php` used to do it; `cron/sync_roster.php` does it here.
- The hub's other 20 tables — `mls_listings`, hot sheets, the email
  broadcaster, pipeline events — are not here and are not needed.
