## Gestion technique des états de commande FNE

Le module crée et utilise deux états de commande PrestaShop dédiés au suivi
du cycle de traitement fiscal FNE.

### État 1 — Facture FNE réussie

Nom : **Envoyé à la FNE**

Cet état est utilisé lorsqu'une facture PrestaShop a été transmise avec succès à la FNE.

Configuration fonctionnelle :

logable      = true
invoice      = false
paid         = false
shipped      = false
delivery     = false
send_email   = false

L'identifiant de cet état est stocké dans : FNE_ORDER_STATE_ID

Le contrôleur d'envoi de facture récupère cet identifiant dans la configuration
et l'applique à la commande uniquement lorsque la réponse FNE est un succès
(200 ou 201).

### État 2 — Facture FNE puis avoir FNE réussi

Nom : **Envoyé vers FNE - Puis Avoir FNE : Annulée**

Cet état représente une commande dont la facture FNE a été créée puis annulée
fiscalement par la génération réussie d'un avoir FNE.

L'état est volontairement distinct de l'état natif PrestaShop Annulée, afin
de permettre d'identifier explicitement les commandes ayant suivi ce cycle
fiscal FNE.

Il doit néanmoins reproduire le comportement fonctionnel d'un état
d'annulation.

Configuration :
logable       = false
invoice       = false
paid          = false
shipped       = false
delivery      = false
send_email    = false
pdf_invoice   = false
pdf_delivery  = false

L'identifiant de cet état est stocké dans : FNE_REFUND_ORDER_STATE_ID

Le contrôleur de génération d'avoir récupère cet identifiant et l'applique
uniquement lorsque l'API FNE confirme avec succès la génération de l'avoir
(code HTTP 200 ou 201).

## Relation entre traitement FNE et état PrestaShop

Le changement d'état est effectué après l'enregistrement de l'opération dans fne_log.

Le flux technique est donc :
Appel API FNE
     ↓
Réponse FNE
     ↓
Enregistrement dans fne_log
     ↓
Vérification du code HTTP
     ↓
200 / 201 ?
     │
     ├── NON → aucun changement d'état
     │
     └── OUI
          ↓
     changement d'état PrestaShop
          ↓
     redirection vers la commande

Cette séquence permet de conserver dans fne_log la trace de l'opération
avant de modifier l'état de la commande.

## Principe d'installation et d'évolution

La création des états FNE relève de la responsabilité de FneInstaller.

La classe principale du module ne doit pas contenir directement toute la
logique de création des états.

À terme, l'architecture doit suivre le principe :
Sendtofne_ci
      │
      └── FneInstaller
             ├── installation
             ├── désinstallation
             ├── base de données
             ├── configuration
             ├── hooks
             └── états de commande

L'ajout du deuxième état FNE sur une installation existante doit être réalisé
par une procédure de migration/upgrade.

Une évolution du module ne doit pas nécessiter la désinstallation puis la
réinstallation du module.

En particulier, l'ajout ou la modification d'un état de commande ne doit pas
entraîner la suppression de fne_log.

## Conservation de l'historique FNE

La table fne_log constitue une donnée historique durable du module.

Elle n'est pas soumise à une contrainte de clé étrangère vers
ps_orders.

Le champ : *id_order* constitue une référence historique vers la commande PrestaShop concernée.

Cette conception permet notamment :

* de conserver l'historique FNE même après le nettoyage de commandes anciennes ;
* de conserver les données FNE indépendamment des données commerciales opérationnelles ;
* de restaurer ou importer l'historique FNE indépendamment d'une sauvegarde de commandes donnée ;
* d'éviter qu'une contrainte SQL empêche la maintenance ou la restauration sélective de la base.

L'historique FNE ne doit donc pas être considéré comme une simple donnée
temporaire liée au cycle de vie de ps_orders.

## Journal technique et fonctionnel FNE : `ps_fne_log`

### 1. Présentation

La table `ps_fne_log` constitue le journal central des opérations réalisées par le module SendToFNE CI auprès de l'API FNE de la DGI.

Elle permet de conserver les échanges avec l'API et de reconstituer l'historique des opérations fiscales associées aux commandes PrestaShop.

Cette table est également exploitée par plusieurs composants du module pour déterminer l'état fonctionnel FNE d'une commande.

La table utilise le préfixe de base de données PrestaShop (`_DB_PREFIX_`) afin de rester compatible avec les installations utilisant un préfixe personnalisé.

### 2. Structure de la table

La structure actuelle de la table est définie dans `sql/install.sql`.

| Champ | Type | Description |
|---|---|---|
| `id_fne_log` | INT AUTO_INCREMENT | Identifiant unique de l'enregistrement |
| `id_order` | INT | Identifiant de la commande PrestaShop concernée |
| `type` | ENUM('INVOICE', 'REFUND') | Nature de l'opération FNE |
| `fne_reference` | VARCHAR(64) | Référence fiscale retournée par l'API |
| `response_code` | VARCHAR(10) | Code HTTP de la réponse |
| `response_body` | TEXT | Corps de la réponse retournée par l'API |
| `created_at` | DATETIME | Date et heure de l'enregistrement |

### 3. Enregistrement des opérations

Chaque tentative d'émission d'une facture ou d'un avoir FNE donne lieu à un enregistrement dans cette table.

Les principaux types d'opérations sont :

- `INVOICE` : émission d'une facture FNE.
- `REFUND` : émission d'un avoir FNE.

Le champ `response_code` permet de distinguer les opérations réussies des tentatives échouées.

Les codes `200` et `201` sont les seuls codes actuellement reconnus comme des succès par le module.

Le champ `response_body` conserve la réponse JSON de l'API. Il permet notamment de retrouver les informations retournées par la DGI, y compris les références fiscales et les identifiants techniques.

### 4. Exploitation des données

La table `ps_fne_log` est exploitée par différents composants du module.

#### 4.1. Contrôle anti-doublon

Avant de procéder à une nouvelle émission, le module consulte le journal pour vérifier si une opération du même type a déjà été réalisée avec succès.

Pour les avoirs, une opération `REFUND` ayant un code `200` ou `201` empêche l'émission d'un nouvel avoir pour la commande concernée.

Les tentatives échouées ne sont pas prises en compte dans ce contrôle.

#### 4.2. Récupération des informations de facture

Lors de la génération d'un avoir, le module récupère la dernière facture FNE réussie de la commande.

Les informations suivantes sont notamment exploitées :

- L'identifiant technique de la facture (`invoice.id`).
- Les lignes de facture (`invoice.items`).
- Les identifiants techniques FNE des articles.
- Les quantités associées aux lignes.

Ces informations permettent de construire la requête de remboursement adressée à l'API FNE.

#### 4.3. Détermination de l'état FNE d'une commande

Le service `FneInvoiceStatesService` exploite les enregistrements de `ps_fne_log` pour déterminer l'état FNE courant des commandes.

Le principe retenu est le suivant :

1. Identifier les opérations réussies uniquement (`200` ou `201`).
2. Ignorer les tentatives échouées.
3. Identifier la dernière opération réussie selon `created_at` et `id_fne_log`.
4. Déterminer l'état FNE correspondant.
5. Récupérer séparément les références de facture et d'avoir.

Une opération échouée plus récente ne doit jamais remplacer l'état déterminé par une opération précédemment réussie.

#### 4.4. Historique des opérations

Le journal conserve les différentes tentatives d'émission, ce qui permet de consulter les réponses de l'API et de comprendre les éventuels échecs.

Les enregistrements ne sont pas remplacés par les nouvelles tentatives : chaque opération dispose de son propre identifiant.

### 5. Relations avec les tables PrestaShop

Le champ `id_order` permet de rattacher chaque opération FNE à une commande PrestaShop.

Aucune clé étrangère physique entre `ps_fne_log` et `ps_orders` n'est définie.

Ce choix est volontaire : le journal FNE doit rester autonome et durable, indépendamment des opérations de maintenance ou de suppression pouvant affecter les données commerciales de PrestaShop.

Le rattachement entre les deux tables est donc réalisé au niveau applicatif et dans les requêtes SQL du module.

### 6. Conservation et cycle de vie

La table `ps_fne_log` est conservée lors de la désinstallation du module.

La méthode `uninstall()` de `FneInstaller` ne supprime pas cette table ni son contenu.

Les configurations persistantes et les états de commande spécifiques à FNE sont également conservés conformément à la stratégie de désinstallation retenue.

Lors d'une réinstallation, le module doit pouvoir retrouver les structures et états déjà existants sans provoquer de doublons.

### 7. Principe architectural

La table `ps_fne_log` ne doit pas être considérée comme une simple table de traces techniques.

Elle constitue simultanément :

- Le journal des échanges avec l'API FNE.
- L'historique des opérations fiscales d'une commande.
- Une source de référence pour les contrôles anti-doublons.
- Une source de décision pour déterminer l'état FNE courant.

**Toute évolution du module impliquant l'émission de factures, la génération d'avoirs ou la détermination de l'état FNE doit préserver la cohérence de ce journal.**