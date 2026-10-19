# Security

Forever Tracker connects to your game databases and its Forge can write to them. Please report vulnerabilities
privately through GitHub's *Report a vulnerability* button (Security tab) rather than in a public issue.

## Recommendations

- Keep `config.ini` out of the web root (it is by default: only `public/` is served).
- Give the website a database user with only the rights it needs; without write access to `world` and `hotfixes`,
  the Forge can still preview and download SQL.
- Use long passwords for local administrators and HTTPS when the tracker is reachable from the internet.
- Set `public = 0` in `[site]` to show the tracker only to administrators.
- Back up your databases before applying Forge operations.
