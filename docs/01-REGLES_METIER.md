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

### 3. Un échec d'avoir FNE ne doit pas annuler la commande

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


### 4. Principe général

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

## Conservation des données et stratégie de désinstallation

### 1. Principe général

Le module SendToFNE CI assure une intégration entre PrestaShop et la plateforme de Facture Normalisée Électronique (FNE) de la Direction Générale des Impôts (DGI) de Côte d'Ivoire.

Dans ce contexte, certaines données générées par le module constituent un historique technique et fonctionnel des opérations fiscales réalisées.

La désinstallation du module ne doit donc pas entraîner la suppression systématique de ces données.

Le principe retenu est le suivant :

**La désinstallation du module supprime ses éléments d'intégration au Back-Office, mais conserve les données nécessaires à la traçabilité des opérations FNE.**

### 2. Conservation de la table `ps_fne_log`

La table `ps_fne_log` constitue le journal des opérations effectuées auprès de la plateforme FNE.

Elle conserve notamment :

- L'identifiant de la commande PrestaShop concernée (`id_order`).
- Le type d'opération : `INVOICE` ou `REFUND`.
- La référence fiscale FNE (`fne_reference`).
- Le code de réponse HTTP (`response_code`).
- Le corps de la réponse de l'API (`response_body`).
- La date et l'heure de l'opération (`created_at`).

Ce journal permet notamment de :

- Retrouver les références des factures et avoirs FNE.
- Distinguer les opérations réussies des tentatives échouées.
- Consulter les réponses retournées par l'API.
- Reconstituer l'historique des échanges avec la DGI.
- Déterminer le dernier état fonctionnel FNE d'une commande.

### Règle de conservation

La table `ps_fne_log` et son contenu sont conservés lors de la désinstallation du module.

Aucune suppression automatique des enregistrements FNE n'est effectuée.

Cette décision permet de préserver la traçabilité des opérations fiscales, même lorsque le module n'est plus installé.

### 3. Éléments supprimés lors de la désinstallation

La désinstallation supprime les éléments administratifs propres au module qui doivent disparaître de l'interface PrestaShop.

Il s'agit notamment de :

- L'onglet Back-Office `AdminFneInvoiceStates` (Commandes FNE).
- Les éléments d'intégration administrative associés au module, conformément au mécanisme de désinstallation de PrestaShop.

La désinstallation ne supprime pas la table `ps_fne_log`.

### 4. Conservation des configurations et des états de commande

Les configurations persistantes du module sont conservées lors de la désinstallation.

Les deux états de commande spécifiques à FNE sont également conservés :

- `Envoyé à la FNE`.
- `Envoyé vers FNE - Puis Avoir FNE : Annulée`.

Cette conservation évite de supprimer des références utilisées par les commandes existantes ou par l'historique des opérations fiscales.

### 5. Réinstallation du module

La réinstallation du module doit pouvoir s'effectuer sans compromettre l'historique FNE précédemment enregistré.

La table `ps_fne_log` étant conservée, les opérations antérieures restent disponibles.

Le mécanisme d'installation vérifie également l'existence des états de commande FNE et leur configuration avant d'en créer de nouveaux.

Cette vérification limite les risques de création de doublons et permet de retrouver les états déjà présents dans la boutique.

### 6. Tests de validation

La stratégie de conservation a été vérifiée dans deux environnements distincts.

**Test 1 — Nouvelle installation PrestaShop**

- Installation du module sur une nouvelle boutique.
- Vérification de la création des composants nécessaires.
- Désinstallation du module.
- Vérification de la suppression des éléments administratifs.
- Vérification de la conservation des données prévues.

**Test 2 — Boutique PrestaShop existante**

- Sauvegarde préalable de la base de données.
- Vérification de la présence des enregistrements dans `ps_fne_log`.
- Désinstallation du module depuis le Back-Office.
- Vérification de l'existence de la table après désinstallation.
- Vérification de la conservation de l'intégralité des enregistrements.

Les deux tests ont été exécutés avec succès.

### 7. Décision technique

La stratégie de désinstallation de SendToFNE CI repose donc sur une séparation claire entre :

- Les composants techniques et administratifs du module, qui peuvent être supprimés.
- Les données historiques et fiscales, qui doivent être conservées.

**La désinstallation de SendToFNE CI n'est pas une opération de purge des données FNE.**

Toute suppression volontaire de l'historique devra faire l'objet d'une opération distincte, explicite et maîtrisée, et non d'une conséquence automatique de la désinstallation du module.

## Journal des opérations FNE et règles de traçabilité

### 1. Rôle du journal FNE

Le module SendToFNE CI conserve un journal des échanges avec l'API de la Facture Normalisée Électronique (FNE) dans la table `ps_fne_log`.

Ce journal constitue une source de référence pour connaître les opérations fiscales effectuées sur chaque commande PrestaShop.

Il remplit trois fonctions principales :

- **Journal technique :** conserver les réponses retournées par l'API FNE, les codes HTTP et les informations relatives aux échanges.
- **Historique fonctionnel :** retracer les factures et les avoirs FNE associés à chaque commande.
- **Source de décision :** déterminer si une opération FNE a effectivement réussi et identifier la dernière opération réussie.

### 2. Distinction entre tentative et opération réussie

Toute tentative d'envoi vers l'API FNE doit être enregistrée dans le journal, qu'elle aboutisse ou échoue.

Cependant, une tentative enregistrée ne signifie pas nécessairement que l'opération fiscale a été réalisée.

Les codes HTTP suivants sont considérés comme des succès :

- `200` : opération réussie.
- `201` : opération créée avec succès.

Tout autre code de réponse correspond à une opération qui ne doit pas être considérée comme réussie.

**Règle fondamentale : seules les opérations ayant obtenu un code HTTP `200` ou `201` peuvent être prises en compte dans les décisions fonctionnelles du module.**

Les tentatives échouées restent conservées pour permettre leur consultation et leur analyse, mais elles ne doivent pas modifier l'état fonctionnel FNE d'une commande.

### 3. Utilisation du journal comme source de décision

Le module utilise `ps_fne_log` pour déterminer les opérations fiscales effectivement réalisées.

Les règles suivantes s'appliquent :

- Une facture FNE est considérée comme émise uniquement lorsqu'une opération `INVOICE` possède un code de réponse `200` ou `201`.
- Un avoir FNE est considéré comme émis uniquement lorsqu'une opération `REFUND` possède un code de réponse `200` ou `201`.
- Une tentative échouée ne remplace jamais une opération précédemment réussie.
- L'état FNE courant d'une commande est déterminé à partir de sa dernière opération FNE réussie.

Cette règle garantit la cohérence entre l'historique technique et l'état fonctionnel affiché dans le Back-Office.

### 4. Gestion des références FNE

Les références fiscales sont récupérées à partir des réponses JSON enregistrées dans `response_body`.

Les règles d'extraction sont différentes selon le type d'opération :

- **Facture (`INVOICE`) :** la référence fiscale se trouve dans `invoice.reference`.
- **Avoir (`REFUND`) :** la référence fiscale se trouve dans la propriété racine `reference` de la réponse.

L'identifiant technique de la facture FNE est également conservé dans `invoice.id` pour permettre notamment la génération ultérieure d'un avoir associé à cette facture.

### 5. Anti-doublon et intégrité des opérations

Le journal FNE participe au mécanisme de prévention des doubles émissions.

Une opération fiscale réussie doit empêcher une nouvelle émission identique lorsque le fonctionnement du module prévoit cette interdiction.

Pour les avoirs, le mécanisme anti-double avoir repose sur la présence d'au moins une opération `REFUND` réussie pour la commande concernée.

Une tentative d'avoir échouée ne bloque donc pas une nouvelle tentative.

### 6. Conservation de l'historique

Les règles de conservation du journal FNE sont définies dans la section
**« Conservation des données et stratégie de désinstallation »**

Le journal `ps_fne_log` constitue un historique durable des opérations
fiscales et doit être préservé conformément à cette politique.