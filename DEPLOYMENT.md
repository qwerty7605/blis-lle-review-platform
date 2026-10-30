# Deploying BLIS to AWS

Written for a single EC2 instance running the production compose stack. Work
through it in order — the sequence matters more than it looks, and several
steps exist only to stop a later one failing confusingly.

Everything here assumes `docker-compose.prod.yml`, `Dockerfile.prod` and
`nginx/production.conf`, not the development files of the same name.

---

## 0. Before you touch AWS

**Set a billing alarm first.** Billing and Cost Management → Budgets →
threshold $1. Five minutes, and it is how you learn about a mistake in hours
rather than at the end of the month.

**Check which free tier you have.** Billing → Free tier. Accounts created
before mid-2025 get 12 months of 750 instance-hours; newer ones get a credit
allowance instead. This changes how long "free" lasts, not what you build.

**Pick a region and never change it.** `ap-southeast-1` (Singapore) is the
closest to the Philippines. Resources do not move between regions.

**You need a hostname.** The auth cookie is created with `Secure` whenever
`APP_ENV` is not `local`, which means browsers refuse to send it over plain
HTTP and **login silently fails**. Let's Encrypt will not issue for a raw IP
or for `*.amazonaws.com`, so a name is not optional. Either a domain you buy,
or a free `*.duckdns.org` subdomain, which gets a genuine trusted certificate.

---

## 1. Launch the instance

- **AMI**: Ubuntu 24.04 LTS
- **Type**: `t3.micro` (1 GB RAM — see the sizing note in `docker/php/www.conf`)
- **Storage**: 30 GB gp3
- **Key pair**: create one and download it. It is offered exactly once.
- **Security group** — exactly three inbound rules:

  | Port | Source | Why |
  |------|--------|-----|
  | 22 | your IP only | SSH. Not `0.0.0.0/0`. |
  | 80 | anywhere | HTTP, and Let's Encrypt's challenge |
  | 443 | anywhere | HTTPS |

  Do **not** open 3306. MySQL is unpublished in the compose file and must stay
  that way; tunnel over SSH if you need a GUI.

- **Elastic IP**: allocate one and associate it. Without it the public IP
  changes on every stop/start and your DNS silently points nowhere.

---

## 2. Prepare the instance

```bash
ssh -i your-key.pem ubuntu@<elastic-ip>

# Swap. The Vite build peaks around 600 MB and MySQL will already be holding
# ~400 MB of the 1 GB; without swap the build is OOM-killed with an error
# that never mentions memory.
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

# Docker
sudo apt-get update && sudo apt-get install -y docker.io docker-compose-v2 git
sudo usermod -aG docker ubuntu
exit   # log back in for the group to apply
```

---

## 3. Get the code and its secrets

```bash
git clone git@github.com:eggeol/BLIS-beta.git
cd BLIS-beta
```

Neither `.env` nor `.env.docker` is in the repo — that is the design working.
Create both by hand:

```bash
cp .env.example .env
cp .env.docker.example .env.docker
```

Generate fresh credentials. **Use hex, not base64**: `.env` treats `#` as a
comment and expands `$`, so a base64 password containing either silently
truncates or mangles, and you get an authentication error that makes no sense
against the file you are reading.

```bash
openssl rand -hex 32   # MYSQL_PASSWORD  (must match DB_PASSWORD in .env)
openssl rand -hex 32   # MYSQL_ROOT_PASSWORD (different from the above)
```

Then edit `.env`. At minimum:

```
APP_ENV=production
APP_DEBUG=false          # see the warning below
APP_URL=https://your-hostname
FRONTEND_URL=https://your-hostname
DB_HOST=db
DB_PASSWORD=<same as MYSQL_PASSWORD>
LOG_STACK=daily
LOG_LEVEL=warning
MAIL_* (see .env.example for Gmail / Brevo / SES)
```

> **`APP_DEBUG=true` in production prints `DB_PASSWORD` to anyone who triggers
> an uncaught exception.** It is the single highest-risk value in the file.

```bash
chmod 600 .env .env.docker
docker compose -f docker-compose.prod.yml run --rm php php artisan key:generate
```

`APP_KEY` encrypts sessions and cookies. Generate it once, per environment,
and leave it alone — changing it invalidates every existing session.

---

## 4. First deploy, over plain HTTP

Get the application working before adding TLS. Debugging an app and a
certificate simultaneously is miserable.

```bash
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml run --rm php php artisan migrate --force
```

The build takes several minutes on a `t3.micro`. **Expect the queue worker to
crash-loop until migrations create the `jobs` table** — it settles on its own
afterwards.

Verify:

```bash
curl -I http://<elastic-ip>/            # 200, serves the SPA
curl -I http://<elastic-ip>/dashboard/rooms   # 200, not 404 — history fallback
curl    http://<elastic-ip>/up          # 200, and it really checks MySQL
```

Create the first admin account:

```bash
docker compose -f docker-compose.prod.yml run --rm -e XDG_CONFIG_HOME=/tmp php php artisan tinker
```

Then inside tinker, with your own name, address and a password that
satisfies the policy (`email_verified_at` is not mass-assignable, so it is set
on its own):

```php
$admin = new App\Models\User(['name' => 'Your Name', 'email' => 'you@lnu.edu.ph', 'role' => 'admin', 'is_active' => true, 'password' => 'a-long-password']);
$admin->email_verified_at = now();
$admin->save();
```

Do not use the demo admin's address (`DEMO_ADMIN_EMAIL`, section 9): the demo
seeder stops if that address already belongs to a real account.

---

## 5. DNS

Point an `A` record at the Elastic IP. For DuckDNS, set the IP on their
dashboard. Confirm before continuing, because certbot's challenge depends on
it resolving:

```bash
dig +short your-hostname
```

---

## 6. TLS

```bash
sudo apt-get install -y certbot
docker compose -f docker-compose.prod.yml stop nginx
sudo certbot certonly --standalone -d your-hostname
docker compose -f docker-compose.prod.yml start nginx
```

Then add a TLS listener to `nginx/production.conf`, mount
`/etc/letsencrypt` into the nginx service, and uncomment `443:443` in
`docker-compose.prod.yml`.

**Only once HTTPS works**, uncomment the HSTS header in
`nginx/production.conf`. Browsers honour it for the full `max-age` and you
cannot take it back — start with a short one if unsure.

Renewal: `sudo certbot renew --dry-run`, then let the packaged timer handle it.

---

## 7. Backups — do this before real students use it

```bash
sudo mkdir -p /var/backups/blis
crontab -e
```

```
30 2 * * * /home/ubuntu/BLIS-beta/scripts/backup-database.sh >> /var/log/blis-backup.log 2>&1
```

Run it once by hand to confirm, then **restore it once into a throwaway
database**. The restore command is in the script's header. A backup you have
never restored is a guess.

Backups on the same instance survive a bad migration but not a lost instance.
Copying them to S3 is the obvious next step.

---

## 8. Deploying updates

```bash
git pull
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml run --rm php php artisan migrate --force
```

The entrypoint rebuilds the config, route and view caches on every start.

> **Cached config ignores `.env` entirely.** Editing a value and restarting is
> required; editing it alone does nothing. This trips up nearly everybody once.

Also note `opcache.validate_timestamps=0` — PHP never re-reads changed files,
so code changes need a container restart, not a file copy. That is intended:
it is a real speed win on a small instance.

---

## 9. Demo data

`php artisan db:seed` builds a realistic demo: about 4,200 accounts, this
term's exams with 20,000 attempts, Review Bot content, and ten graduated
cohorts with board results. Every demo account is flagged `users.is_demo`,
which is how `demo:purge` removes all of it without touching real accounts.

Take a backup first (section 7), then:

```bash
docker compose -f docker-compose.prod.yml run --rm php php artisan db:seed --force
```

It signs in as follows (the seed output prints the same table):

| Account | Sign in with | Password |
|---|---|---|
| Admin | `DEMO_ADMIN_EMAIL` (default in `app/Services/Demo/DemoData.php`) | `DEMO_ADMIN_PASSWORD` |
| Teachers | `teacher1@lnu.edu.ph`, `teacher2@…`, `teacher3@…` | `DEMO_SEED_PASSWORD` |
| Students | `…@blis.test` addresses listed in the output | `DEMO_SEED_PASSWORD` |

Each password falls back to a default written in this repository; pass
`-e DEMO_ADMIN_PASSWORD=… -e DEMO_SEED_PASSWORD=…` to `run` to use your own.
If the admin or a teacher address already belongs to a real account, seeding
stops without changing anything. Demo accounts cannot request password-reset
emails. `SHOWCASE_COHORT_SIZE` (default 300) and `SHOWCASE_HISTORY_YEARS`
(default 10) shrink the demo if needed.

While it is loaded, real accounts share the site with it: dashboards count
the demo students. (Real students never get the demo's Review Bot questions:
a student only practises questions built from items they were dealt in their
own finished exams.) Take it out when you are done:

```bash
docker compose -f docker-compose.prod.yml run --rm php php artisan demo:purge --dry-run
docker compose -f docker-compose.prod.yml run --rm php php artisan demo:purge --force
```

The dry run lists what will go, including any real activity on demo content.
The demo admin and teacher accounts go too, so keep a real admin account of
your own (section 4). A demo Library file that a real exam uses is kept and
handed to that exam's author. If a real exam has attempts inside a demo
section, the purge refuses and removes nothing; unassign that exam from the
section first.

---

## 10. Resetting lnu-blis.live for the November 2026 changes

The branch `Major-Changes/NewLLM` changes the tagging taxonomy, exam types, the
Estimated Outcome model and the `let_models` table, with no conversion of old
data: it expects to start from an empty database. Merging it and letting the
auto-deploy run `migrate` on the old data is not the plan. Instead, once, by
hand, in this order. **Every account, exam, result and Library file on the
site is deleted**, so tell staff and students first, and do it when no exam is
scheduled.

All commands run on the server, in `~/BLIS-beta`, unless they say otherwise.
`P` stands for `docker compose -f docker-compose.prod.yml`:

```bash
cd ~/BLIS-beta
P="docker compose -f docker-compose.prod.yml"
```

### 1. Pause the auto-deploy

Comment out the `auto-deploy.sh` line in the crontab, keeping a copy:

```bash
crontab -l > ~/crontab.before-reset
crontab -e        # put a # in front of: * * * * * $HOME/auto-deploy.sh
crontab -l        # check the line is commented out
```

Then make sure no deploy is half-way through (it holds this lock while it
runs; `idle` means none is):

```bash
flock -n /tmp/blis-auto-deploy.lock echo idle
```

If it prints nothing, a deploy is running: wait for `~/auto-deploy.log` to
say `live` or `FAILED`, then check again.

### 2. Take a backup and copy it off the server

```bash
./scripts/backup-database.sh
ls -lh /var/backups/blis/ | tail -3
```

The script refuses to keep a dump that fails `gzip -t`, is under 1 KB, or
lacks mysqldump's `Dump completed` line, so a new `blis-YYYY-MM-DD-HHMM.sql.gz`
in the listing has passed those checks. Check it once more and note its name:

```bash
B=$(ls -t /var/backups/blis/blis-*.sql.gz | head -1); echo "$B"
gzip -t "$B" && gunzip -c "$B" | tail -1                     # -- Dump completed on ...
gunzip -c "$B" | grep -c '^INSERT INTO `users`'             # more than 0
```

Then, **from your own computer**, copy it off:

```bash
scp -i your-key.pem ubuntu@<elastic-ip>:/var/backups/blis/blis-YYYY-MM-DD-HHMM.sql.gz .
gunzip -t blis-YYYY-MM-DD-HHMM.sql.gz && echo copied
```

### 3. Merge, then pull and rebuild by hand

Merge the branch into `main` on GitHub (a pull request from
`Major-Changes/NewLLM`). The auto-deploy is paused, so nothing happens on the
server yet. On the server:

```bash
$P exec -T php php artisan down    # the site answers 503 until step 4 ends
git fetch origin
git checkout main
git pull --ff-only origin main
git log --oneline -1               # the merge commit
$P up -d --build
```

The maintenance flag is kept in the storage volume, so the rebuilt containers
keep it. The build takes several minutes on this instance. The queue worker
and the scheduler start on the new code against the old tables; stop them
until the database is rebuilt:

```bash
$P stop queue scheduler
```

### 4. Rebuild the database and check model version 1

```bash
$P run --rm php php artisan migrate:fresh --force
```

`migrate:fresh` drops every table and runs every migration from the first.
The last one, `2026_11_03_000000_rebuild_let_models_for_logistic_regression`,
loads version 1 of the Estimated Outcome model from
`ml/models/logistic-v1.json`. Check it is there and in use:

```bash
$P run --rm -e XDG_CONFIG_HOME=/tmp php php artisan tinker --execute='dump(DB::table("let_models")->get(["version","status","source","ridge","roc_area","failed_recall"])->toArray());'
```

Expected: one row, version 1, status `active`, source `weka`, ridge 0.5,
ROC area 0.8631, FAILED recall 0.7148. If the table is empty, the image was
built without `ml/models/logistic-v1.json`; do not go on.

The models an administrator retrained before (the old J48 versions) are files
in the storage volume that nothing reads any more. Remove them:

```bash
$P run --rm php sh -c 'rm -rf storage/app/private/let-models'
```

Then start the rest again:

```bash
$P start queue scheduler
$P run --rm php php artisan up
```

### 5. Create the real administrator account

As in section 4 (the tinker commands under "Create the first admin
account"). Everyone was signed out by step 4; sign in with the new account at
`https://lnu-blis.live/login` to check it works.

### 6. Demo data: only if wanted

Skip this unless the site should show the demo. If it should, always pass
your own passwords: without them the demo admin and every demo account use
passwords written in this repository.

```bash
$P run --rm -e DEMO_ADMIN_PASSWORD='<your own>' -e DEMO_SEED_PASSWORD='<your own>' php php artisan db:seed --force
```

The seed prints the accounts at the end (section 9 has the rest). Demo LLE
results are marked as demo data and are never used to retrain the model.

### 7. Check the site, then resume the auto-deploy

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://lnu-blis.live/        # 200
curl -s -o /dev/null -w '%{http_code}\n' https://lnu-blis.live/up      # 200, checks MySQL
$P ps                                                                  # php, nginx, db, queue, scheduler all up
$P logs --tail=20 queue                                                # no errors
```

Signed in as the administrator: the Licensure page shows "Estimated Outcome
model" with version 1 in use; the Exams page's form offers the four exam
types; uploading a Word question set to the Library tags it with the TOS
topics.

Then tell the auto-deploy that this commit is already live, so it does not
deploy it again, and turn it back on:

```bash
git rev-parse HEAD > ~/.deployed-commit
rm -f ~/.failed-commit ~/.waiting-commit
crontab ~/crontab.before-reset
crontab -l                    # the auto-deploy.sh line is back, uncommented
```

A minute later `tail ~/auto-deploy.log` should show nothing new: `main` and
`~/.deployed-commit` agree.

If anything goes wrong before step 7, restore the backup from step 2 into the
`db` service (the command is in the header of `scripts/backup-database.sh`)
after checking out the commit that was live before, which is the one in
`~/.deployed-commit` until step 7 rewrites it.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| Login returns 200 then every request 401s | No HTTPS. The `Secure` cookie is never sent. |
| Verification emails link to `localhost` | `APP_URL` still points at localhost. |
| Refreshing a dashboard page 404s | Using `nginx/default.conf` instead of `production.conf`. |
| Uploads over 2 MB rejected | Dev `php.ini`; production sets 20 M to match nginx. |
| Queue container restarting | Migrations have not run yet. |
| Config change has no effect | Config cache. Restart the container. |
| Build killed with no message | Out of memory. Check swap is active with `free -h`. |
