# [DigiBoard] [23.1.1] - Téléchargement des documents Digirisk réparé

Description : Version corrective. Elle répare le téléchargement des documents Digirisk depuis le tableau de bord, qui échouait selon l'adresse de la page d'origine, aligne les bornes de compatibilité Dolibarr du module sur la ligne réellement livrée et place le code sous analyse statique à chaque modification.

**Cette version demande Saturne 23.2.1 ou supérieur.**

## Améliorations & corrections

### Documents Digirisk

* **Le téléchargement d'un document Digirisk échouait de façon imprévisible.** Le contrôle d'accès du module réécrivait le chemin du fichier dès que l'adresse de la page d'origine contenait le chiffre « 1 » — une parenthèse mal placée transformait le motif recherché en une valeur toujours vraie — et il le faisait même sans adresse d'origine. Le chemin n'est plus réécrit que pour les documents d'une **autre entité**, les seuls qui en ont besoin.

### Compatibilité

* Le module déclare **Dolibarr 23 au minimum et 24 au maximum**. Il annonçait la 19 comme plancher, une ligne qu'il ne sait plus faire fonctionner : **sur un Dolibarr antérieur à la 23, il refusera désormais de s'activer** plutôt que de s'installer pour tomber en erreur ensuite.

### Paquet livré

* **Le paquet embarquait l'outillage interne du dépôt** — intégration continue, analyse statique, dépendances de développement. DigiBoard était le seul module du parc sans règles d'exclusion de paquet, et ne pouvait pas en avoir : le fichier qui les porte était lui-même ignoré depuis la création du dépôt. Le paquet passe de 57 à 38 fichiers, tous utiles au module.

### Documentation du module

* Le changelog reprend le nom attendu par Dolibarr, `ChangeLog.md`. Le cœur le lit pour l'injecter dans la documentation générée du module ; sous l'ancien nom, il ne le trouvait pas sur un serveur Linux.

### Intégration continue

* Les pull requests passent désormais **PHPStan**, un **lint PHP** et un contrôle de **parité des fichiers de langue** français / anglais : une clé ajoutée d'un côté et oubliée de l'autre arrête la chaîne.

## Comparaison des versions [23.1.0](https://github.com/Evarisk/digiboard/compare/23.1.0...23.1.1) et 23.1.1

* #73 [Mod] fix: poser le gabarit .gitattributes, absent du dépôt [`2dca11e`](https://github.com/Evarisk/digiboard/commit/2dca11e)
* #69 [Hook] fix: checkSecureAccess casse les téléchargements Digirisk (referer absent ou contenant « 1 ») [`ee4353f`](https://github.com/Evarisk/digiboard/commit/ee4353f)
* #66 [Mod] fix: renommer le changelog en ChangeLog.md [`0f47ad0`](https://github.com/Evarisk/digiboard/commit/0f47ad0)
* #63 [CI] rework: élaguer les entrées mortes de la baseline [`fc06d17`](https://github.com/Evarisk/digiboard/commit/fc06d17)
* #60 [CI] rework: scanner le socle par dossier plutôt que l'exclure par morceaux [`cd3a9aa`](https://github.com/Evarisk/digiboard/commit/cd3a9aa)
* #56 [CI] fix: exclure les bouchons phan de Saturne de l'analyse [`5944357`](https://github.com/Evarisk/digiboard/commit/5944357)
* #52 [CI] fix: reposer l'exclusion des stubs de test de Saturne [`98d5886`](https://github.com/Evarisk/digiboard/commit/98d5886)
* #48 [CI] rework: aligner phpstan.neon sur le gabarit commun [`f70598e`](https://github.com/Evarisk/digiboard/commit/f70598e)
* #46 [CI] fix: PHPStan ne scanne plus les stubs de test de Saturne [`18a32ce`](https://github.com/Evarisk/digiboard/commit/18a32ce)
* #44 [CI] rework: aligner phpstan.neon et quality.yml sur le gabarit commun [`9784dbb`](https://github.com/Evarisk/digiboard/commit/9784dbb)
* #42 [CI] feat: PHPStan, lint PHP et parité des langues [`3a8024b`](https://github.com/Evarisk/digiboard/commit/3a8024b)
* #40 [Module] rework: bornes de version Dolibarr 23 minimum, 24 maximum [`6d8ce03`](https://github.com/Evarisk/digiboard/commit/6d8ce03)
