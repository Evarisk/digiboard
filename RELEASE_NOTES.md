# [DigiBoard] [23.3.1] - Accueil avec les statistiques d'accidents - Liste des risques hors custom/

Description : L'accueil de DigiBoard s'affiche de nouveau quand les statistiques d'accidents sont activées, y compris en PHP 8. La liste des risques garde ses catégories, ses critères et ses protections quand les modules sont installés dans un autre dossier que `custom/`.

## Améliorations & corrections

### Tableau de bord

* Avec l'option des statistiques d'accidents de Digirisk, l'accueil de DigiBoard plantait en PHP 8 et laissait un avertissement en PHP 7.4. Chaque colonne d'accidents a maintenant son propre titre, et la page s'affiche normalement.

### Liste des risques

* Les catégories de risques, les critères de cotation, les pictogrammes et les protections s'affichent aussi quand Digirisk et Saturne sont installés dans un dossier de modules autre que `custom/`.

## Comparaison des versions [23.3.0](https://github.com/Evarisk/digiboard/compare/23.3.0...23.3.1) et 23.3.1

* [#136] [DashBoard] fix: one header label per accident column, fatal on PHP 8 [`2309a64f`](https://github.com/Evarisk/digiboard/commit/2309a64f)
* [#133] [Risk] fix: chemins de Digirisk et de Saturne sans custom/ en dur [`3d2d7d14`](https://github.com/Evarisk/digiboard/commit/3d2d7d14)
