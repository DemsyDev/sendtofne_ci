<?php

/**
 * Gestion de l'installation, de la désinstallation
 * et des évolutions du module SendToFNE CI.
 *
 * Cette classe centralise les opérations structurelles
 * du module afin de ne pas surcharger la classe principale
 * Sendtofne_ci.
 *
 * Les évolutions du module doivent être réalisées par
 * migration/upgrade et non par désinstallation/réinstallation,
 * afin de préserver les données historiques contenues
 * dans fne_log.
 */
class FneInstaller
{
    /**
     * Module auquel l'installateur est rattaché.
     *
     * @var Module
     */
    private $module;

    /**
     * Initialise l'installateur.
     *
     * @param Module $module Instance du module SendToFNE CI.
     */
    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    /**
     * Installation complète
     *
     * @return bool
     */
    public function install()
    {
        return
            $this->installDatabase()
            && $this->installConfiguration()
            && $this->installTabs()
            && $this->installHooks()
            && $this->installOrderState()
            && $this->installFneRefundOrderState();
    }

    /**
     * Installe les structures de base de données nécessaires au module.
     *
     * Charge le fichier SQL d'installation situé dans le répertoire
     * `sql` du module, remplace le préfixe générique `PREFIX_` par
     * le préfixe réel de la base de données PrestaShop, puis exécute
     * les requêtes SQL contenues dans le fichier.
     *
     * L'utilisation de `PREFIX_` dans le fichier SQL permet au module
     * de fonctionner avec n'importe quel préfixe de base de données
     * configuré dans PrestaShop.
     *
     * Chaque requête SQL est exécutée individuellement afin de pouvoir
     * détecter précisément une erreur lors de l'installation.
     *
     * En cas d'erreur SQL, l'erreur retournée par PrestaShop est
     * enregistrée dans le journal système puis l'installation est
     * interrompue.
     *
     * @return bool
     *         True si toutes les requêtes d'installation ont été
     *         exécutées avec succès, false si le fichier SQL est
     *         introuvable ou si l'une des requêtes échoue.
     */
    protected function installDatabase()
    {
        $file = dirname(__DIR__) . '/sql/install.sql';

        if (!file_exists($file)) {
            return false;
        }

        $sql = file_get_contents($file);

        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        $queries = preg_split('/;\s*[\r\n]+/', $sql);

        foreach ($queries as $query) {

            $query = trim($query);

            if (empty($query)) {
                continue;
            }

            if (!Db::getInstance()->execute($query)) {

                PrestaShopLogger::addLog('[FNE CI] SQL INSTALL : '. Db::getInstance()->getMsgError(), 3);

                return false;
            }
        }

        return true;
    }

    /**
     * Installe les configurations persistantes nécessaires au module FNE.
     *
     * Cette méthode initialise les identifiants des deux états de commande
     * PrestaShop utilisés par le module :
     *
     * - `FNE_ORDER_STATE_ID` :
     *   état appliqué lorsqu'une facture a été envoyée avec succès
     *   vers la FNE ;
     *
     * - `FNE_REFUND_ORDER_STATE_ID` :
     *   état appliqué lorsqu'un avoir FNE a été généré avec succès
     *   pour une facture précédemment envoyée à la FNE.
     *
     * Les valeurs techniques utilisées pour l'accès à l'API FNE
     * (`API_KEY`, `PAYMENT_METHOD`, `TEMPLATE`, etc.) restent dans
     * `config.ini` et ne sont pas gérées par cette méthode.
     *
     * L'installation est interrompue dès qu'une des deux installations
     * d'état échoue.
     *
     * @return bool
     *         True si les deux états ont été correctement installés
     *         ou déjà identifiés dans la configuration, false en cas
     *         d'échec.
     */
    protected function installConfiguration()
    {
        return $this->installOrderState()
            && $this->installFneRefundOrderState();
    }

    /**
     * Installe l'état de commande utilisé après l'envoi réussi
     * d'une facture vers la FNE.
     *
     * L'état est identifié par la configuration
     * `FNE_ORDER_STATE_ID`.
     *
     * Si un identifiant est déjà enregistré, l'existence réelle
     * de l'OrderState correspondant est vérifiée.
     *
     * Si l'état configuré existe, aucune nouvelle création n'est
     * effectuée.
     *
     * Si l'identifiant configuré ne correspond plus à un OrderState
     * existant, la configuration obsolète est supprimée puis un nouvel
     * état est créé.
     *
     * L'état créé possède les caractéristiques suivantes :
     *
     * - nom : `Envoyé à la FNE` ;
     * - couleur : `#5bc0de` ;
     * - `logable` : true ;
     * - `send_email` : false ;
     * - `invoice` : false.
     *
     * L'identifiant de l'état nouvellement créé est ensuite enregistré
     * dans `FNE_ORDER_STATE_ID`.
     *
     * En cas d'échec de création ou d'enregistrement de la configuration,
     * la méthode retourne false.
     *
     * Si l'enregistrement de la configuration échoue après la création
     * de l'état, l'état nouvellement créé est supprimé afin d'éviter
     * de laisser une donnée d'installation orpheline.
     *
     * @return bool
     *         True si l'état existe déjà ou a été créé avec succès,
     *         false en cas d'échec.
     */
    protected function installOrderState()
    {
        $configuredStateId = (int)Configuration::get('FNE_ORDER_STATE_ID');

        if ($configuredStateId > 0) {

            $existingState = new OrderState($configuredStateId);

            if (Validate::isLoadedObject($existingState)) {
                return true;
            }

            /*
            * L'ID est enregistré dans la configuration,
            * mais l'OrderState correspondant n'existe plus
            * réellement dans ps_order_state.
            */
            Configuration::deleteByName('FNE_ORDER_STATE_ID');
        }

        $state = new OrderState();

        foreach (Language::getLanguages() as $lang) {
            $state->name[$lang['id_lang']] = 'Envoyé à la FNE';
        }

        $state->color = '#5bc0de';
        $state->logable = true;
        $state->send_email = false;
        $state->invoice = false;

        if (!$state->add()) {
            return false;
        }

        if (!Configuration::updateValue('FNE_ORDER_STATE_ID', (int)$state->id)){
            /*
            * La configuration n'a pas pu être enregistrée.
            * L'état venant d'être créé est donc supprimé afin
            * d'éviter de laisser un état FNE orphelin.
            */
            $state->delete();

            return false;
        }

        return true;
    }

    /**
     * Installe l'état de commande utilisé après la génération réussie
     * d'un avoir FNE.
     *
     * Cet état représente une commande dont :
     *
     * - la facture FNE a été créée ;
     * - un avoir FNE a ensuite été généré avec succès ;
     * - la commande doit désormais être considérée comme annulée
     *   dans PrestaShop.
     *
     * L'état est identifié par la configuration
     * `FNE_REFUND_ORDER_STATE_ID`.
     *
     * Si un identifiant est déjà enregistré, l'existence réelle
     * de l'OrderState correspondant est vérifiée.
     *
     * Si l'état configuré existe, aucune nouvelle création n'est
     * effectuée.
     *
     * Si l'identifiant configuré ne correspond plus à un OrderState
     * existant, la configuration obsolète est supprimée puis un nouvel
     * état est créé.
     *
     * L'état créé est volontairement configuré comme un état
     * d'annulation :
     *
     * - nom : `Envoyé vers FNE - Puis Avoir FNE : Annulée` ;
     * - `logable` : false ;
     * - `paid` : false ;
     * - `shipped` : false ;
     * - `delivery` : false ;
     * - `invoice` : false ;
     * - `pdf_invoice` : false ;
     * - `pdf_delivery` : false ;
     * - `send_email` : false.
     *
     * L'identifiant de l'état nouvellement créé est ensuite enregistré
     * dans `FNE_REFUND_ORDER_STATE_ID`.
     *
     * En cas d'échec de création ou d'enregistrement de la configuration,
     * la méthode retourne false.
     *
     * Si l'enregistrement de la configuration échoue après la création
     * de l'état, l'état nouvellement créé est supprimé afin d'éviter
     * de laisser une donnée d'installation orpheline.
     *
     * @return bool
     *         True si l'état existe déjà ou a été créé avec succès,
     *         false en cas d'échec.
     */
    protected function installFneRefundOrderState()
    {
        $configuredStateId = (int)Configuration::get('FNE_REFUND_ORDER_STATE_ID');

        if ($configuredStateId > 0) {

            $existingState = new OrderState($configuredStateId);

            if (Validate::isLoadedObject($existingState)) {
                return true;
            }

            /*
            * L'ID est enregistré dans la configuration,
            * mais l'OrderState correspondant n'existe plus
            * réellement dans ps_order_state.
            */
            Configuration::deleteByName('FNE_REFUND_ORDER_STATE_ID');
        }

        $state = new OrderState();

        foreach (Language::getLanguages() as $lang) {
            $state->name[$lang['id_lang']] = 'Envoyé vers FNE - Puis Avoir FNE : Annulée';
        }

        $state->color = '#2C3E50';

        $state->logable = false;
        $state->send_email = false;
        $state->invoice = false;
        $state->paid = false;
        $state->shipped = false;
        $state->delivery = false;
        $state->pdf_invoice = false;
        $state->pdf_delivery = false;

        if (!$state->add()) {
            return false;
        }

        if (!Configuration::updateValue('FNE_REFUND_ORDER_STATE_ID', (int)$state->id)) {
            /*
            * La configuration n'a pas pu être enregistrée.
            * L'état venant d'être créé est donc supprimé afin
            * d'éviter de laisser un état FNE orphelin.
            */
            $state->delete();

            return false;
        }

        return true;
    }

    /**
     * Installe l'état de commande utilisé après
     * la génération réussie d'un avoir FNE.
     *
     * Cet état représente une commande dont la facture FNE
     * a été générée puis annulée fiscalement par un avoir FNE.
     *
     * - la facture FNE a été créée ;
     * - l'avoir FNE a ensuite été créé avec succès ;
     * - la commande doit désormais être considérée
     *   comme annulée dans PrestaShop.
     *
     * L'état est donc configuré "volontairement" selon la logique
     * d'un état d'annulation :
     *
     * - logable = false ;
     * - paid = false ;
     * - shipped = false ;
     * - delivery = false ;
     * - invoice = false ;
     * - pdf_invoice = false ;
     * - pdf_delivery = false ;
     * - send_email = false.
     * 
     * - la commande n'est pas considérée comme valide ;
     * - elle n'est pas considérée comme payée ;
     * - elle n'est pas considérée comme expédiée ;
     * - aucune facture PrestaShop n'est générée ;
     * - aucun e-mail automatique n'est envoyé.
     *
     * Si l'état existe déjà dans la configuration,
     * aucune nouvelle création n'est effectuée.
     *
     * @return bool
     *         True si l'état existe déjà ou a été créé
     *         avec succès, false en cas d'échec.
     */
    public function installFneRefundOrderState()
    {
        // Vérification réelle de l'OrderState configuré
        $configuredStateId = (int)Configuration::get('FNE_REFUND_ORDER_STATE_ID');

        if ($configuredStateId > 0) {

            $existingState = new OrderState($configuredStateId);

            if (Validate::isLoadedObject($existingState)) {
                return true;
            }

            /*
             * L'ID est enregistré dans la configuration,
             * mais l'OrderState correspondant n'existe plus
             * réellement dans ps_order_state.
             *
             * On poursuit donc l'installation afin de recréer
             * un état valide.
             */
            Configuration::deleteByName('FNE_REFUND_ORDER_STATE_ID');
        }
        // Vérification réelle de l'OrderState configuré

        $state = new OrderState();

        foreach (Language::getLanguages() as $lang) {
            $state->name[$lang['id_lang']] = 'Envoyé vers FNE - Puis Avoir FNE : Annulée';
        }

        $state->color = '#2C3E50';

        $state->logable = false;
        $state->send_email = false;
        $state->invoice = false;
        $state->paid = false;
        $state->shipped = false;
        $state->delivery = false;
        $state->pdf_invoice = false;
        $state->pdf_delivery = false;

        if (!$state->add()) {
            return false;
        }

        return (bool)Configuration::updateValue('FNE_REFUND_ORDER_STATE_ID', (int)$state->id);
    }
}