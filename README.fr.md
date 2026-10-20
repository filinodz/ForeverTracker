# Forever Tracker

Développé par **[FilinoDZ](https://github.com/filinodz)**.

**Voyez ce qui manque à votre serveur WoW Forever, et corrigez-le.**

Forever Tracker lit le client officiel du jeu build après build, liste chaque ressource livrée par Blizzard et la
vérifie sur votre serveur basé sur TrinityCore : données du core, PNJ placés, butin, marchands, entraîneurs,
donneurs de quêtes et scripts. Chaque manque est accompagné de sa cause et de sa correction, et la **Forge** applique
les corrections pour vous, jusqu’à placer les PNJ manquants à leur position officielle.

[English](README.md) · [Installation](docs/INSTALL.md) · [Configuration](docs/CONFIGURATION.md) · [La Forge](docs/FORGE.md) · [Localisateur et placements](docs/SPAWNS.md) · [Fonctionnement](docs/ARCHITECTURE.md) · [Changelog](CHANGELOG.md)

![Tableau de bord](docs/screenshots/overview.png)

## Installation en deux minutes (Windows)

1. [Téléchargez ForeverTracker](https://github.com/filinodz/ForeverTracker/archive/refs/heads/main.zip) (ou `git clone`) et décompressez-le.
2. Double-cliquez sur **`ForeverTracker.exe`**. Il trouve PHP et Python, démarre le site et ouvre l’assistant
   d’installation.
3. L’assistant trouve tout seul votre serveur et votre client de jeu : confirmez, choisissez un mot de passe
   administrateur, et il lance la première analyse.

| ![Lanceur](docs/screenshots/launcher.png) | ![Assistant d’installation](docs/screenshots/setup.png) |
|---|---|

Windows peut afficher « Windows a protégé votre ordinateur » au premier lancement, car le lanceur n’est pas signé :
*Informations complémentaires* › *Exécuter quand même*. Son code source est dans [`tools/launcher`](tools/launcher)
et `build-exe.ps1` le recompile avec le compilateur C# fourni par Windows. Linux, ou à la main : voir
[le guide d’installation](docs/INSTALL.md).

## Nouveautés de la 1.1

### Lanceur et assistant d’installation

`ForeverTracker.exe` trouve un PHP 8 avec `mysqli` (même un PHP portable livré avec un repack, dont il active les
extensions) et Python, puis lance le site. L’assistant lit votre `worldserver.conf` (bases de données, dossier de
données, console distante), teste les bases, trouve le client du jeu grâce à son `.build.info`, extrait ses données
avec le `mapextractor` de votre core (ou utilise les DB2 du serveur), écrit `config.ini`, puis lance l’analyse et
le localisateur en affichant la progression.

![Assistant : bases de données](docs/screenshots/setup-database.png)

### Localisateur et placements

Beaucoup de quêtes ne marchent pas seulement parce qu’un PNJ n’a jamais été placé : le modèle existe, la quête y
renvoie, mais personne ne se tient dans le monde. Le **localisateur** trouve les donneurs et valideurs de quête,
objectifs, marchands, entraîneurs, butins rares et boss que votre serveur connaît sans les avoir placés, et demande
aux données Forever de Wowhead où ils se trouvent ; les tables de cartes du client convertissent ces positions en
coordonnées du monde (à quelques mètres près en extérieur). La Forge les place ensuite :

- **sur le serveur en marche**, par la console distante et les commandes `.forge` du
  [module du core](core/README.md) : le serveur calcule lui-même la hauteur du sol, bâtiments compris, et chaque
  placement peut être annulé ;
- **en jeu**, avec l’addon **ForeverForge** (`/forge`) : il place toute la file pour vous, et *Placer ici* sert aux
  boss des donjons ;
- **dans la base de données**, ou sous forme de **commandes MJ**.

| ![Placements](docs/screenshots/spawns.png) | ![Placer la sélection](docs/screenshots/spawns-dialog.png) |
|---|---|

### Une Forge qui n’échoue plus sur « duplicate entry »

Les corrections sont stockées comme des opérations, plus comme du SQL brut. Appliquer une sélection la fusionne,
insère chaque ligne une seule fois, écarte celles qui existent déjà sur le serveur, et garde une annulation qui
retire exactement ce qui a été ajouté. Appliquer deux fois, des corrections qui se recoupent, des lignes ajoutées à
la main : tout est sûr. L’aperçu distingue les lignes nouvelles, existantes et partagées.

![Aperçu SQL](docs/screenshots/forge-sql.png)

## Comment fonctionne le tracker

1. **Conserver un build du client.** Les fichiers DB2 du client officiel (extraits avec le `mapextractor` de votre
   core) sont gardés dans `data/builds/<build>/<locale>/`.
2. **Comparer le client à votre serveur.** `analyse` lit ces snapshots, les DB2 du serveur, ses bases `world`,
   `hotfixes` et `auth`, et les données de cartes. Chaque ressource est disponible, incomplète ou manquante, avec
   un score et le détail des vérifications.
3. **Consulter les résultats** sur le site : couverture, fiches, infobulles, liste du travail restant et
   changements entre les builds du client.
4. **Corriger.** La Forge prévisualise, applique et annule les corrections ; le localisateur et la page Placements
   ajoutent ce qui manque dans le monde. Relancez l’analyse pour actualiser.

Le tracker nécessite un accès aux données de votre serveur ; sa seule adresse ne suffit pas pour l’analyser.

## Fonctionnalités

- **Couverture de 13 types de ressources** : donjons et raids, boss, zones, quêtes, objets, sorts, talents,
  recettes, montures, mascottes, jouets, hauts faits et points de vol, avec un statut, un score et le détail des
  vérifications.
- **Changelog officiel** : chaque build du client est comparé au précédent, champ par champ. Les administrateurs
  peuvent y joindre les notes de mise à jour de Blizzard.
- **Infobulles comme en jeu** au survol, calculées avec les formules du core : niveau d’objet, lien, armure, dégâts
  et DPS, caractéristiques, effets « Utiliser / Équipé / Chances quand vous touchez », ensembles et bonus, prix de
  vente ; coût, portée, incantation, recharge, composants et description des sorts. En français et en anglais.
- **La Forge** : données du core, entraîneurs, marchands, instances et placements, en masse, avec aperçu SQL, une
  transaction par opération, un historique et une annulation.

| | |
|---|---|
| ![Infobulle](docs/screenshots/items-tooltip.png) | ![Fiche d’objet](docs/screenshots/item.png) |
| ![Changelog](docs/screenshots/changelog.png) | ![Travail restant](docs/screenshots/todo.png) |
| ![La Forge](docs/screenshots/forge.png) | ![Vérifications d’un donjon](docs/screenshots/dungeon-forge.png) |

Thème clair, interface française et téléphone :

| | | |
|---|---|---|
| ![Thème clair en français](docs/screenshots/item-light-fr.png) | ![Téléphone](docs/screenshots/mobile-overview.png) | ![Liste sur téléphone](docs/screenshots/mobile-quests.png) |

## La Forge

| Correction | Ce qu’elle écrit |
|---|---|
| Données du core | Copie dans la base `hotfixes` les enregistrements officiels que le core ne connaît pas, avec tout ce dont ils dépendent |
| Entraîneurs | Enseigne les sorts de classe et de métier que personne n’enseigne, chez les entraîneurs placés de la même spécialité |
| Marchands | Fait vendre les objets sans source par le marchand de votre choix |
| Instances | Crée la ligne `instance_template` des donjons et raids |
| Placements | Place les PNJ et objets du monde dont le contenu a besoin, à leur position officielle ([localisateur](docs/SPAWNS.md)) |

`tools/forge-check.php` teste toutes les corrections avec le moteur du site, dans des transactions toujours
annulées. Voir [docs/FORGE.md](docs/FORGE.md).

## À la main (tout système)

Prérequis : PHP 8.0+ avec `mysqli` (et `gmp` pour la connexion par compte de jeu), Python 3.9+ (bibliothèque
standard uniquement), le client en ligne de commande `mysql`, les bases `world`, `hotfixes` et `auth` du serveur, et
le client officiel.

```bash
./start.sh                                  # puis ouvrez http://127.0.0.1:8090/setup sur cette machine
```

ou sans l’assistant :

```bash
cp config.sample.ini config.ini            # puis modifiez-le
mapextractor -i "<client>" -o extract -e 2 -l frFR
python tools/tracker.py snapshot --dbc extract/dbc --build 70450
python tools/tracker.py analyse
python tools/tracker.py locate               # optionnel : PNJ non placés et leurs positions
./start.sh                                  # Windows : start.bat
```

Le guide complet est dans [docs/INSTALL.md](docs/INSTALL.md).

## Sécurité

- L’analyse ne fait que lire les bases du jeu.
- La Forge n’écrit qu’après confirmation d’un administrateur, dans une transaction par opération, et garde le SQL
  exact et son annulation dans l’historique. Sauvegardez quand même vos bases.
- L’assistant d’installation ne répond qu’à l’ordinateur qui fait tourner ForeverTracker tant qu’il n’est pas
  configuré, puis seulement aux administrateurs.

## Licence

MIT — voir [LICENSE](LICENSE). Outil non officiel, sans lien avec Blizzard Entertainment. Contenu et icônes de World
of Warcraft © Blizzard Entertainment ; les icônes sont chargées depuis le CDN d’images public de Wowhead.
