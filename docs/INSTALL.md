# Installation

## Requirements

| | |
|---|---|
| Web server | PHP 8.0 or newer with `mysqli`; `gmp` to let game masters log in with their game account. PHP's built-in server is enough for a private tool; Apache and nginx work too. |
| Analysis | Python 3.9 or newer (standard library only) and the `mysql` command-line client |
| Server | A TrinityCore-based WoW Forever server: its `world`, `hotfixes` and `auth` databases, its DB2 folder (`data/dbc/<locale>`), and optionally its `maps`, `vmaps` and `mmaps` folders |
| Client | The official WoW Forever client, to extract its DB2 files with your core's `mapextractor` |

## 1. Get the code

```bash
git clone https://github.com/filinodz/ForeverTracker.git
cd ForeverTracker
```

## 2. Configure

```bash
cp config.sample.ini config.ini
```

Fill in `[database]` (host, port, user, password, and the database names) and `[server]` (the root folder of your
server and the paths of its data). `tracker` is the database that receives the tracker's own `ft_*` tables: any
database your user can write to; it can be a new empty one. See [CONFIGURATION.md](CONFIGURATION.md).

`config.ini` sits outside `public/`, so the web server never serves it. It is ignored by git.

## 3. Store a client build

Extract the DB2 files of the official client with the `mapextractor` of your core (`-e 2` extracts only the DB2
files), in English, French or both:

```bash
mapextractor -i "C:/Games/WoW Forever" -o extract -e 2 -l enUS
mapextractor -i "C:/Games/WoW Forever" -o extract -e 2 -l frFR
```

Then store the tables the tracker needs for that build (about 70 files per locale):

```bash
python tools/tracker.py snapshot --dbc extract/dbc --build 70450 --date 2026-10-15
```

Repeat this step for each new client build: the changelog compares every stored build with the previous one. The
extraction folder can be deleted afterwards.

## 4. Analyse

```bash
python tools/tracker.py analyse
```

The analysis reads the snapshots, your server's DB2 files, databases and map folders, then loads the results into
the `tracker` database (about three minutes). It only reads the game databases. Run it again after every change
on your server, or use *Run again* in the Forge.

Add `--wowhead` once to name the quests your server does not know: their names come from Wowhead's public Forever
tooltips, slowly and politely, and are cached in `data/wowhead-quests.json`.

## 5. Serve the website

**PHP built-in server** (a private tool on your machine or your LAN):

```bash
./start.sh            # Windows: start.bat
```

`FT_PHP`, `FT_PHP_ARGS` and `FT_PORT` choose the PHP binary, extra arguments (for example `-c php.ini`) and the
port (8090 by default).

**Apache**: point the virtual host (or an alias) to `public/`. The bundled `.htaccess` sends every request that is
not a file to `index.php`; `mod_rewrite` must be enabled.

**nginx**:

```nginx
root /var/www/ForeverTracker/public;
location / { try_files $uri /index.php$is_args$args; }
location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
```

The tracker also works in a sub-folder (`https://example.com/tracker/`): links follow the folder of `index.php`.

## 6. Administrators

The Forge, the patch notes and the history are reserved to administrators. Two kinds of accounts can log in:

- **Game masters**: Battle.net e-mail and password, checked the way the login server does (SRP6), with at least
  the GM level of `[admin] gm_level` (3 by default). Needs the PHP `gmp` extension.
- **Local administrators**, declared in `config.ini`:

  ```bash
  php tools/hash-password.php "a long password"
  ```

  ```ini
  [admins]
  admin = "$2y$10$…"
  ```

## Updating

```bash
git pull
python tools/tracker.py analyse
```

The analysis recreates the `ft_*` tables it owns; the Forge history (`ft_forge_log`) and the patch notes
(`ft_notes`) are kept.
