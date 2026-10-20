# Forever Tracker

Développé par **[FilinoDZ](https://github.com/filinodz)**.

**Voyez ce qui manque à votre serveur WoW Forever, et corrigez-le.**

Forever Tracker lit le client officiel du jeu build après build, liste chaque ressource livrée par Blizzard et la
vérifie sur votre serveur basé sur TrinityCore : données du core, PNJ placés, butin, marchands, entraîneurs,
donneurs de quêtes et scripts. Chaque manque est accompagné de sa cause et de sa correction, et la **Forge** peut
appliquer les corrections courantes pour vous.

[English](README.md) · [Installation](docs/INSTALL.md) · [Configuration](docs/CONFIGURATION.md) · [La Forge](docs/FORGE.md) · [Localisateur et placements](docs/SPAWNS.md) · [Fonctionnement](docs/ARCHITECTURE.md)

![Tableau de bord](docs/screenshots/overview.png)

## Comment fonctionne le tracker

1. **Extraire et conserver un build du client.** Utilisez le `mapextractor` de votre core pour extraire les
   fichiers DB2 du client officiel, puis lancez `snapshot` pour conserver les tables nécessaires dans
   `data/builds/<build>/<locale>/`.
2. **Comparer le client à votre serveur.** Lancez `analyse` : le tracker lit les snapshots, les DB2 du serveur,
   ses bases `world`, `hotfixes` et `auth`, et les données de cartes disponibles. Chaque ressource reçoit un
   statut (disponible, incomplète ou manquante), un score et le détail des vérifications.
3. **Consulter les résultats.** L’analyse écrit ses résultats dans les tables `ft_*` de sa propre base. Le site
   PHP affiche la couverture, les fiches, les infobulles, la liste du travail restant et les changements entre
   les builds du client conservés.
4. **Corriger si nécessaire.** Un administrateur peut prévisualiser le SQL de la Forge, le télécharger ou
   appliquer les corrections prises en charge, puis relancer l’analyse. À chaque nouveau build du client,
   ajoutez un snapshot pour actualiser la comparaison.

Le tracker nécessite un accès aux données de votre serveur ; sa seule adresse ne suffit pas pour l’analyser.
Le [guide d’architecture](docs/ARCHITECTURE.md) détaille le fonctionnement interne.

## Fonctionnalités

- **Couverture de 13 types de ressources** : donjons et raids, boss, zones, quêtes, objets, sorts, talents,
  recettes, montures, mascottes, jouets, hauts faits et points de vol, avec un statut, un score et le détail des
  vérifications.
- **Changelog officiel** : chaque build du client est comparé au précédent, champ par champ. Les administrateurs
  peuvent y joindre les notes de mise à jour de Blizzard.
- **Infobulles comme en jeu** au survol, calculées avec les formules du core : niveau d’objet, lien, armure, dégâts
  et DPS, caractéristiques, effets « Utiliser / Équipé / Chances quand vous touchez », ensembles et bonus, prix de
  vente ; coût, portée, incantation, recharge, composants et description des sorts. En français et en anglais.
- **La Forge** : les administrateurs créent ce qui manque à partir des données officielles, en masse, avec aperçu
  SQL, une transaction par opération et une annulation enregistrée. Les lignes déjà présentes sont écartées :
  appliquer deux fois ou choisir des corrections qui se recoupent n’échoue jamais.
- **Localisateur et placements** : trouve les donneurs de quête, objectifs, marchands, entraîneurs, butins rares et boss
  que votre serveur connaît mais n’a jamais placés, et leur position dans le jeu officiel (données Forever de Wowhead
  converties en coordonnées du monde). La Forge les place sur le serveur en marche, dans la base, ou en jeu avec
  l’addon ForeverForge.
- **Lanceur et assistant d’installation** : double-cliquez sur `ForeverTracker.bat` ; il trouve PHP et Python, puis
  l’assistant trouve votre serveur et votre client de jeu, écrit la configuration et lance la première analyse.

| | |
|---|---|
| ![Infobulle](docs/screenshots/items-tooltip.png) | ![La Forge](docs/screenshots/forge.png) |
| ![Placements](docs/screenshots/spawns.png) | ![Assistant d’installation](docs/screenshots/setup.png) |

## La Forge

| Correction | Ce qu’elle écrit |
|---|---|
| Données du core | Copie dans la base `hotfixes` les enregistrements officiels que le core ne connaît pas, avec tout ce dont ils dépendent |
| Entraîneurs | Enseigne les sorts de classe et de métier que personne n’enseigne, chez les entraîneurs placés de la même spécialité |
| Marchands | Fait vendre les objets sans source par le marchand de votre choix |
| Instances | Crée la ligne `instance_template` des donjons et raids |
| Placements | Place les PNJ et objets du monde dont le contenu a besoin, à leur position officielle ([localisateur](docs/SPAWNS.md)) |

## Démarrage rapide

**Windows :** téléchargez le code, double-cliquez sur `ForeverTracker.bat` et suivez l’assistant d’installation.

**À la main** (tout système). Prérequis : PHP 8.0+ avec `mysqli` (et `gmp` pour la connexion par compte de jeu), Python 3.9+ (bibliothèque
standard uniquement), le client en ligne de commande `mysql`, les bases `world`, `hotfixes` et `auth` du serveur, et
le client officiel.

```bash
cp config.sample.ini config.ini            # puis modifiez-le
mapextractor -i "<client>" -o extract -e 2 -l frFR
python tools/tracker.py snapshot --dbc extract/dbc --build 70450
python tools/tracker.py analyse
python tools/tracker.py locate               # optionnel : PNJ non placés et leurs positions
./start.sh                                  # Windows : start.bat
```

Ouvrez `http://127.0.0.1:8090/`. Le guide complet est dans [docs/INSTALL.md](docs/INSTALL.md).

## Sécurité

L’analyse ne fait que lire les bases du jeu. La Forge n’écrit qu’après confirmation d’un administrateur, dans une
transaction par opération, et garde le SQL exact et son annulation dans l’historique. Sauvegardez quand même vos
bases.

## Licence

MIT — voir [LICENSE](LICENSE). Outil non officiel, sans lien avec Blizzard Entertainment. Contenu et icônes de World
of Warcraft © Blizzard Entertainment ; les icônes sont chargées depuis le CDN d’images public de Wowhead.
