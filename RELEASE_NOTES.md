# [DigiBoard] [23.2.0] - Liste simplifiée des risques

Description : Tous vos risques, tous vos établissements, un seul écran. DigiBoard 23.2.0 ajoute une liste des risques pensée pour le suivi au quotidien : on voit d'un coup d'œil où sont les priorités, qui s'en occupe et où en est le plan d'action.

**Cette version demande Saturne 23.2.1 ou supérieur.**

## 🎬 La nouveauté en vidéo

[![La liste simplifiée des risques en vidéo (48 s)](https://raw.githubusercontent.com/nicolas-eoxia/digiboard/assets/release-23.2.0/video_poster_23.2.0.jpeg)](https://github.com/Evarisk/digiboard/releases/download/23.2.0/digiboard-23.2.0.mp4)

*Cliquez sur l'image pour lancer la vidéo (48 s).*

## 🚀 Pack nouveautés

### Liste simplifiée des risques

![Listing simplifié des risques](https://raw.githubusercontent.com/nicolas-eoxia/digiboard/assets/release-23.2.0/risk_list_23.2.0.png)

**Le document unique se pilote enfin depuis une seule page.** Plus besoin d'ouvrir chaque groupement ou unité de travail pour savoir où vous en êtes : la nouvelle page **Listing simplifié des risques** rassemble tous les risques avec leur cotation, leur emplacement et leur plan d'action.

* **Les priorités sautent aux yeux.** En haut de page, un compteur par niveau — faible, à planifier, à traiter, inacceptable, non évalué. Un clic sur un compteur n'affiche plus que les risques de ce niveau : la préparation d'un comité ou d'une revue de direction commence là.
* **Le plan d'action sous les yeux, sans changer de page.** Chaque risque affiche ses actions correctives, leur responsable, leur échéance et une barre d'avancement. On repère tout de suite un risque à planifier sans action, ou un plan resté à mi-chemin.
* **Professionnels et environnementaux, chacun leur onglet.** Les deux registres se consultent séparément, avec les mêmes outils.
* **Un clic pour agir.** La fiche du risque ou de la tâche s'ouvre directement depuis la liste.

### Filtrer en un instant

![Filtre par catégorie de danger](https://raw.githubusercontent.com/nicolas-eoxia/digiboard/assets/release-23.2.0/risk_list_categories.png)

* Filtrez par **établissement**, par **groupement ou unité de travail** et par **catégorie de danger** — chaque catégorie avec son pictogramme, pour la retrouver sans lire.
* Une recherche libre retrouve un risque, une unité ou une tâche en quelques lettres.
* Exemple : « tous les risques de chute de hauteur encore à planifier sur le site de Lyon » s'affiche en quelques clics : les filtres se combinent avec les compteurs.

### Pensée pour les groupes multi-sites

* Avec Multicompany, la liste réunit les risques de **toutes les entités partagées** et indique l'établissement de chacun. Un responsable QSE suit l'ensemble de ses sites depuis un seul écran.
* Un clic sur un risque ou une tâche d'une autre entité **bascule automatiquement dans cette entité** et ouvre la fiche.

La page est accessible depuis le menu DigiBoard et depuis le menu de Digirisk, qui doit être activé. **Après la mise à jour, désactivez puis réactivez DigiBoard** pour faire apparaître les nouvelles entrées de menu.

## 🛠️ Pack améliorations

* **Documents Digirisk multi-entités sous Dolibarr 24** : un document d'une autre entité ouvert depuis le tableau de bord renvoyait « Le fichier n'existe pas ». Il s'ouvre de nouveau.
* **DigiBoard parle anglais** : le module dispose désormais d'une traduction anglaise complète.
* **Partage Multicompany** : le bloc de partage des statistiques DigiRisk de la fiche entité affichait des codes techniques (`DIGIRISKSTATSSharing`) ; il est traduit.
* **La bonne langue pour chacun** : les textes du module suivent la langue choisie par l'utilisateur, et non plus celle de son navigateur.

## 👉 Essayez-la maintenant

**Allez tester la liste simplifiée des risques sur la démo : [http://demo.digirisk.com/](http://demo.digirisk.com/)**

## Comparaison des versions [23.1.1](https://github.com/Evarisk/digiboard/compare/23.1.1...23.2.0) et 23.2.0

* #85 [Lang] fix: charger la langue du module après celle de l'utilisateur [`e2d75de`](https://github.com/Evarisk/digiboard/commit/e2d75de)
* #83 [Risk] fix: ouvrir un risque ou une tâche d'une autre entité depuis la liste simplifiée [`d4479f5`](https://github.com/Evarisk/digiboard/commit/d4479f5)
* #81 [Risk] fix: update cotation pill colors (grey for low, white text on unacceptable) and hide unassessed when empty [`cc2f277`](https://github.com/Evarisk/digiboard/commit/cc2f277)
* #79 [Risk] add: simplified interactive risk listing page in Notion style [`66b4f8a`](https://github.com/Evarisk/digiboard/commit/66b4f8a)
* #77 [Lang] fix: add missing English translations and load lang file for MultiCompany sharing [`e635426`](https://github.com/Evarisk/digiboard/commit/e635426)
* #71 [Hook] fix: read requested entity from the request in checkSecureAccess [`888b1c2`](https://github.com/Evarisk/digiboard/commit/888b1c2)
