# Contributing

Thanks for helping. Bug reports, data corrections and pull requests are welcome.

## Reporting a problem

Open an issue with:

- what you expected and what you saw (a screenshot helps);
- the resource concerned (type and ID, e.g. `item 19019`);
- your client build, your core (repository and commit) and the last lines of `python tools/tracker.py analyse`.

A wrong status usually comes from a rule that does not know a way your server provides something: say how players
get that resource on your server.

## Development

```bash
cp config.sample.ini config.ini     # point it to a test server
python tools/tracker.py analyse
./start.sh
```

- PHP: no framework, no build step. Keep every visible text in both languages with `t('English', 'Français')`.
- Python: standard library only. Run `python tools/check_fixes.py` after changing `tools/ft/forge.py`.
- Keep the interface plain: existing colours and components, no new dependencies.
- Screenshots of the README: `node tools/screenshots.mjs http://127.0.0.1:8090/` (Node 22+, Chrome or Edge).

## Pull requests

One change per pull request, with a short description of what it fixes and how you checked it. Never commit
`config.ini`, client files or anything under `data/`.
