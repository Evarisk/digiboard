# [DigiBoard] [23.2.1] - Correctif de sécurité

Description : DigiBoard 23.2.1 est une version de sécurité. Elle ne contient qu'un seul correctif, qui concerne les installations **multi-entités** (module Multicompany). **La mise à jour est recommandée pour toutes ces installations.**

**Cette version demande Saturne 23.2.1 ou supérieur.**

## 🔒 Sécurité

* **Documents Digirisk d'une autre entité** : depuis le tableau de bord, DigiBoard permet d'ouvrir les documents Digirisk des autres entités, par exemple le document unique d'un autre établissement. Il ne vérifiait pas que l'utilisateur avait accès à l'entité concernée. Un utilisateur rattaché à une seule entité pouvait ainsi récupérer les documents Digirisk des autres. DigiBoard applique désormais la même règle que la connexion à une entité : seuls les utilisateurs autorisés sur une entité peuvent en ouvrir les documents.

Les installations sans Multicompany ne sont pas concernées. Rien ne change pour les utilisateurs autorisés.

## Comparaison des versions [23.2.0](https://github.com/Evarisk/digiboard/compare/23.2.0...23.2.1) et 23.2.1

* #94 [Hook] fix: check the user may access the entity before serving its documents [`64dcfb1`](https://github.com/Evarisk/digiboard/commit/64dcfb1)
