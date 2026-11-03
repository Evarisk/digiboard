# [DigiBoard] [23.3.0] - Export Excel de la liste des risques - Critères de cotation et protections

Description : La liste simplifiée des risques s'exporte en **classeur Excel** prêt pour un comité ou une revue de direction (risques, plan d'actions, historique des évaluations, protections, synthèses par établissement, par unité et par catégorie, cartographie, échéancier), ou en **CSV**. Elle affiche aussi les **critères de cotation** des risques évalués en méthode avancée et les **protections** (EPI, EPC) de chaque unité, avec un **thème modernisé**.

**Cette version demande Saturne 23.7.0 ou supérieur.** Sur un Saturne plus ancien, DigiBoard refuse désormais de s'activer, avec un message clair, au lieu de provoquer une erreur.

## 🎬 La nouveauté en vidéo

<a href="https://github.com/Evarisk/digiboard/releases/download/23.3.0/digiboard-23.3.0.mp4"><img src="https://raw.githubusercontent.com/nicolas-eoxia/digiboard/refs/heads/assets/release-23.3.0/video_poster_23.3.0.jpeg" alt="Toute la liste des risques dans Excel, en vidéo (41 s)"></a>

*Cliquez sur l'image pour voir la vidéo (41 s).*

## Nouvelles fonctionnalités et innovations

### Liste simplifiée des risques : exports

* **Export Excel** : un bouton à côté de l'export CSV télécharge tous les risques de la liste, avec ses filtres et son tri. Le classeur propose une vue par onglet :
  * un sommaire avec les filtres appliqués, les chiffres clés, la répartition par niveau et des liens vers chaque onglet ;
  * les risques, le plan d'actions et l'**historique des évaluations**, avec la cotation précédente et l'écart ;
  * les **protections** EPI et EPC ;
  * des synthèses **par établissement**, **par groupement et unité** et **par catégorie de danger** ;
  * une **cartographie** groupement × catégorie, colorée selon la cotation la plus haute ;
  * la répartition des **critères** de cotation et un **échéancier** mensuel des actions.
* Le classeur est prêt à exploiter :
  * les cotations, les dates et les parts sont de vrais nombres ;
  * les niveaux prennent les couleurs de Digirisk ;
  * les en-têtes sont figés et filtrables ;
  * chaque onglet s'imprime sur une largeur de page.
* Avec Multicompany, l'export couvre tous les établissements que la liste réunit, avec un onglet de synthèse par établissement.
* **Export CSV** : une ligne par risque, avec sa dernière évaluation, ses critères, ses protections et l'ensemble de son plan d'actions.

<img src="https://raw.githubusercontent.com/nicolas-eoxia/digiboard/refs/heads/assets/release-23.3.0/export-liste.png" alt="Liste simplifiée des risques : compteurs par niveau, filtres et boutons Export Excel et CSV">
<img src="https://raw.githubusercontent.com/nicolas-eoxia/digiboard/refs/heads/assets/release-23.3.0/classeur-cartographie.png" alt="Onglet « Cartographie » du classeur : groupements et unités face aux catégories de danger, colorés selon la cotation">

<img src="https://raw.githubusercontent.com/nicolas-eoxia/digiboard/refs/heads/assets/release-23.3.0/classeur-sommaire.png" alt="Sommaire du classeur : filtres, chiffres clés et contenu">

### Liste simplifiée des risques : lecture

* **Critères de cotation** : sous la cotation d'un risque évalué en méthode avancée, des jauges affichent la gravité, l'exposition, l'occurrence, la formation et la protection. Pour un risque environnemental, ce sont la fréquence, l'historique, la maîtrise et le milieu. Le libellé du niveau apparaît au survol.
* **Colonne Protections** : elle montre les pictogrammes des EPI et EPC signalés sur le groupement ou l'unité du risque, avec le détail au survol.
* **Thème moderne** : le tableau de bord et les listes de DigiBoard adoptent le thème de Digirisk 23.6.0.

<img src="https://raw.githubusercontent.com/nicolas-eoxia/digiboard/refs/heads/assets/release-23.3.0/criteres-protections.png" alt="Jauges des critères sous la cotation et pictogrammes des protections EPI et EPC">

## Améliorations & corrections

### Liste des risques

* La date d'évaluation manquait le plus souvent, à l'écran comme dans l'export. Elle suit désormais la règle de Digirisk : la date saisie si l'option est active, sinon la date de création.
* Les groupements et unités dont un parent est à la corbeille sont masqués.
* Le compteur « Inacceptable » s'affiche en blanc, ce qui le rend lisible.
* La colonne « Moyens de protection » devient « Protections ».

### Sécurité et compatibilité

* Les liens et appels AJAX de la liste portent le jeton anti-CSRF.
* DigiBoard vérifie la version de Saturne avant de s'activer.
* Saturne est retrouvé quand DigiBoard est installé par lien symbolique.


## Testez les nouveautés maintenant

**Allez tester l'export Excel de la liste simplifiée des risques sur la démo : [https://demodoli.digirisk.com/](https://demodoli.digirisk.com/)**

## Comparaison des versions [23.2.1](https://github.com/Evarisk/digiboard/compare/23.2.1...23.3.0) et 23.3.0

* [#129] [CI] ci: actions GitHub en Node 24 (checkout@v7, cache@v6) [`372b610e`](https://github.com/Evarisk/digiboard/commit/372b610e)
* [#127] [CI] ci: contrôle « API Saturne publiée » dans la CI qualité [`0b8eafaf`](https://github.com/Evarisk/digiboard/commit/0b8eafaf)
* [#125] [CI] ci: retrait du workflow Generate Auto Changelog [`d1d99049`](https://github.com/Evarisk/digiboard/commit/d1d99049)
* [#123] [Mod] feat: version minimale de Saturne, activation refusee sur un Saturne trop ancien [`35c51858`](https://github.com/Evarisk/digiboard/commit/35c51858)
* [#121] [Risk] rework: export Excel du listing des risques sur SaturneXlsxExport [`52e6b651`](https://github.com/Evarisk/digiboard/commit/52e6b651)
* [#119] [Risk] fix: show the assessment date the way Digirisk does [`4a9d264a`](https://github.com/Evarisk/digiboard/commit/4a9d264a)
* [#117] [Risk] feat: add a multi-sheet Excel export to the simplified risk list [`7dba86aa`](https://github.com/Evarisk/digiboard/commit/7dba86aa)
* [#115] [Risk] fix: rename protection means column to protections [`7884f55a`](https://github.com/Evarisk/digiboard/commit/7884f55a)
* [#113] [CI] ci: refuser les inclusions relatives de Saturne [`27154d9b`](https://github.com/Evarisk/digiboard/commit/27154d9b)
* [#113] [Saturne] fix: inclure Saturne par dol_include_once / dol_buildpath [`2cbbb878`](https://github.com/Evarisk/digiboard/commit/2cbbb878)
* [#111] [Risk] fix: white count on unacceptable risk pill [`f008eabf`](https://github.com/Evarisk/digiboard/commit/f008eabf)
* [#109] [SCSS] feat: thème moderne des écrans DigiBoard [`9f802b86`](https://github.com/Evarisk/digiboard/commit/9f802b86)
* [#103] [Risk] fix: masquer les groupements et unités dont un parent est à la corbeille [`7a959c4f`](https://github.com/Evarisk/digiboard/commit/7a959c4f)
* [#101] [Risk] feat: add cotation criteria, protection means and csv export to risk list [`bd9109e8`](https://github.com/Evarisk/digiboard/commit/bd9109e8)
* [#99] [CSRF] fix: ajouter le token CSRF aux liens et appels AJAX [`de41e2ca`](https://github.com/Evarisk/digiboard/commit/de41e2ca)
* [#94] [Hook] fix: check the user may access the entity before serving its documents [`64dcfb12`](https://github.com/Evarisk/digiboard/commit/64dcfb12)

