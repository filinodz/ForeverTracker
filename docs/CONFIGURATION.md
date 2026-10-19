# Configuration

Everything is in `config.ini`, read by the website (PHP) and by the analysis (Python). Start from
`config.sample.ini`. Values in quotes are strings; paths may use `/` on every system.

## `[site]`

| Key | Default | |
|---|---|---|
| `name` | `Forever Tracker` | Name shown in the sidebar, the titles and the footer |
| `default_language` | `en` | `en` or `fr`; visitors can switch, their choice is kept in a cookie |
| `public` | `1` | `0` shows only the login page to visitors who are not administrators |
| `home_url`, `home_label` | | Link back to your main website (sidebar button) |
| `repository_url` | | Link to the source code in the footer |

## `[database]`

| Key | |
|---|---|
| `host`, `port`, `user`, `password` | MySQL / MariaDB connection, used by the website and by the analysis |
| `tracker` | Database of the tracker's own tables (`ft_*`) |
| `world`, `hotfixes`, `auth` | Your server's databases |

The user needs read access to `world`, `hotfixes` and `auth`, and write access to `tracker`. The Forge also needs
write access to `world` and `hotfixes`; give it to a separate user if you prefer to keep the website read-only and
apply the downloaded SQL yourself.

## `[server]`

| Key | Example | |
|---|---|---|
| `root` | `C:/WoW/Server` | Root folder of your server; the paths below are relative to it (absolute paths work too) |
| `dbc` | `data/dbc/enUS` | DB2 folder loaded by the worldserver |
| `maps`, `vmaps`, `mmaps` | `data/maps` | Map data, to check that instances have their terrain and navigation |
| `build_file` | `data/server-build.json` | JSON file with the client build of your server data: `{"Build": 70450}` |
| `build` | `0` | The same build, when there is no `build_file` |
| `mysql` | `mysql/bin/mysql.exe` | The `mysql` command-line client; `mysql` on the PATH is used when it is not found |

## `[tools]`

| Key | Default | |
|---|---|---|
| `python` | `python` (`python3` on Linux) | Python used by the *Run again* button of the Forge |

## `[admin]` and `[admins]`

| Key | Default | |
|---|---|---|
| `gm_level` | `3` | Minimum GM level (`auth.account_access`) of game accounts that can log in |
| `vendor_entry` | `0` | Vendor proposed by default in the Forge's *Vendors* tab |

`[admins]` lists local administrators as `name = "password hash"` (see `tools/hash-password.php`).

## Environment

| Variable | |
|---|---|
| `FOREVER_TRACKER_CONFIG` | Path of another configuration file (website and analysis) |
| `FT_PHP`, `FT_PHP_ARGS`, `FT_PORT` | Used by `start.bat` / `start.sh` |

## Running inside another website

A website can serve the tracker under one of its folders by setting `$_SERVER['SCRIPT_NAME']` to
`/<folder>/index.php` and including `public/index.php`; the tracker builds its links from that folder. If the host
website defines the constant `FT_HOST_IDENTITY` (`['account' => auth.account.id, 'username' => auth.account.username,
'name' => display name, 'site_admin' => bool]`) for its logged-in user, the tracker checks that account in the
`auth` database and makes it an administrator when it is a site administrator or a game master of `gm_level`.
