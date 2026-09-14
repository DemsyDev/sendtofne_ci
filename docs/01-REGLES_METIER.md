## Règle métier — Gestion des états de commande après traitement FNE

Le module `SendToFNE CI` utilise les états de commande PrestaShop pour refléter
l'état fonctionnel du traitement fiscal FNE.

### 1. Après une facture FNE réussie

Lorsqu'une facture est transmise à la FNE avec succès, c'est-à-dire lorsque
l'API FNE retourne le code HTTP `200` ou `201`, la commande PrestaShop est
placée dans l'état :

> **Envoyé à la FNE**

Cet état indique que la facture correspondante a été effectivement acceptée
par la FNE.

Un échec de transmission FNE ne doit pas provoquer ce changement d'état.

Ainsi :

```text
Tentative d'envoi de facture
        │
        ├── HTTP 200 ou 201
        │       ↓
        │  Facture FNE réussie
        │       ↓
        │  État : "Envoyé à la FNE"
        │
        └── Autre code / erreur
                ↓
          Aucun changement d'état

### 2. Après génération réussie d'un avoir FNE

Lorsqu'un avoir FNE est généré avec succès, c'est-à-dire lorsque l'API FNE
retourne le code HTTP 200 ou 201, la commande est placée dans l'état :

Envoyé vers FNE - Puis Avoir FNE : Annulée

Cet état représente une situation fonctionnelle précise :

une facture FNE a été créée pour la commande ;
cette facture a ensuite fait l'objet d'un avoir FNE ;
l'avoir FNE a été accepté avec succès par la FNE ;
la commande doit désormais être considérée comme annulée dans PrestaShop.

L'état utilisé après l'avoir est volontairement distinct de l'état
PrestaShop natif Annulée, afin de conserver une indication explicite du
traitement fiscal FNE effectué.

Il doit toutefois se comporter fonctionnellement comme un état d'annulation.

3. Un échec d'avoir FNE ne doit pas annuler la commande

Si la tentative de génération de l'avoir FNE retourne un code différent de
200 ou 201, aucun changement vers l'état :

Envoyé vers FNE - Puis Avoir FNE : Annulée

ne doit être effectué.

La commande reste donc dans son état précédent et la tentative échouée est
conservée dans fne_log.
Ainsi
Tentative de génération de l'avoir
        │
        ├── HTTP 200 ou 201
        │       ↓
        │  Avoir FNE réussi
        │       ↓
        │  État :
        │  "Envoyé vers FNE - Puis Avoir FNE : Annulée"
        │
        └── Autre code / erreur
                ↓
          Aucun changement d'état


4. Principe général

Le changement d'état PrestaShop est une conséquence du succès du traitement
fiscal FNE.

Il ne doit jamais être effectué simplement parce qu'une tentative
d'opération FNE a été réalisée.

Les codes HTTP 200 et 201 constituent les codes de succès retenus par le
module pour les opérations FNE.

Par conséquent :

* tentative FNE ≠ succès FNE ;
* log FNE ≠ succès FNE ;
* code 200 ou 201 = succès FNE ;
* seul un succès FNE peut déclencher le changement d'état correspondant.