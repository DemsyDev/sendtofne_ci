<?php
if (!defined('_PS_VERSION_')) { exit; }

class Sendtofne_ci extends Module
{   
    /**
     * Initialise le module SendToFNE CI.
     *
     * Définit les principales informations d'identification du module
     * utilisées par PrestaShop :
     *
     * - nom technique du module ;
     * - catégorie du module dans le Back Office ;
     * - version du module ;
     * - auteur ;
     * - nom affiché dans le Back Office ;
     * - description du module.
     *
     * Le constructeur appelle ensuite le constructeur parent de la classe
     * Module afin de permettre à PrestaShop d'initialiser correctement
     * l'instance du module.
     *
     * @return void
     */
    public function __construct()
    {
        $this->name = 'sendtofne_ci';
        $this->tab = 'administration';
        $this->version = '1.1.0';
        $this->author = 'Adamodial';
        parent::__construct();
        $this->displayName = 'FNE Côte d’Ivoire';
        $this->description = 'Envoi des factures et avoirs vers la FNE (DGI CI).';
    }

    /**
     * Installe le module SendToFNE CI dans PrestaShop.
     *
     * Effectue les opérations nécessaires à l'installation initiale
     * du module :
     *
     * - exécute l'installation native du module via la méthode parente ;
     * - enregistre le hook `displayAdminOrder` afin d'afficher les
     *   éléments FNE sur la page de détail d'une commande ;
     * - crée les éléments de base de données nécessaires au module ;
     * - crée l'état de commande PrestaShop utilisé pour identifier
     *   une facture ayant été envoyée avec succès vers la FNE.
     *
     * L'installation de l'état de commande utilisé après génération
     * d'un avoir FNE est actuellement désactivée. Elle sera intégrée
     * ultérieurement dans le mécanisme d'installation/migration
     * du module.
     *
     * Chaque étape dépend de la réussite de la précédente grâce
     * à l'opérateur logique `&&`. Si une étape échoue, les étapes
     * suivantes ne sont pas exécutées et la méthode retourne `false`.
     *
     * @return bool
     *         True si toutes les opérations d'installation exécutées
     *         ont réussi, false si l'une d'elles échoue.
     */
    public function install()
    {
        return parent::install()
            && $this->registerHook('displayAdminOrder')
            && $this->installDb()
            && $this->installOrderState();
            // && $this->installFneRefundOrderState();
    }

    /**
     * Installe la structure de base de données du module.
     *
     * Charge le fichier SQL d'installation situé dans le répertoire
     * `sql` du module, puis exécute son contenu via l'instance
     * de base de données de PrestaShop.
     *
     * Le fichier SQL contient les instructions nécessaires à la
     * création des structures persistantes utilisées par le module.
     *
     * @return bool
     *         True si l'exécution du script SQL réussit,
     *         false en cas d'échec.
     */
    private function installDb()
    {
        $sql = file_get_contents(dirname(__FILE__).'/sql/install.sql');
        return Db::getInstance()->execute($sql);
    }

    /**
     * Installe l'état de commande PrestaShop utilisé pour identifier
     * une commande dont la facture a été envoyée vers la FNE.
     *
     * Vérifie d'abord si l'identifiant de l'état est déjà enregistré
     * dans la configuration `FNE_ORDER_STATE_ID`.
     *
     * Si aucun identifiant n'est configuré, un nouvel état de commande
     * est créé avec le libellé :
     *
     *     Envoyé à la FNE
     *
     * L'état est créé pour toutes les langues disponibles dans
     * PrestaShop et configuré avec les propriétés suivantes :
     *
     * - couleur : `#5bc0de` ;
     * - `logable` : true ;
     * - `send_email` : false ;
     * - `invoice` : false.
     *
     * Lorsque la création réussit, l'identifiant de l'état est
     * enregistré dans la configuration `FNE_ORDER_STATE_ID`.
     *
     * Si un état est déjà configuré, aucune nouvelle création
     * n'est effectuée.
     *
     * @return bool
     *         True lorsque l'installation de l'état est considérée
     *         comme réussie.
     */
    private function installOrderState()
    {
        if (!Configuration::get('FNE_ORDER_STATE_ID')) {
            $state = new OrderState();
            foreach (Language::getLanguages() as $lang) {
                $state->name[$lang['id_lang']] = 'Envoyé à la FNE';
            }
            $state->color = '#5bc0de';
            $state->logable = true;
            $state->send_email = false;
            $state->invoice = false;
            if ($state->add()) {
                Configuration::updateValue('FNE_ORDER_STATE_ID', (int)$state->id);
            }
        }
        return true;
    }

    /**
     * Prépare et affiche les éléments FNE sur la page de détail
     * d'une commande dans le Back Office PrestaShop.
     *
     * Cette méthode est appelée par le hook `displayAdminOrder`.
     *
     * Elle effectue notamment les opérations suivantes :
     *
     * - vérifie qu'un identifiant de commande est fourni par le hook ;
     * - récupère l'identifiant de la commande ;
     * - charge l'ensemble des journaux FNE associés à la commande ;
     * - vérifie si une facture FNE a déjà été générée avec succès ;
     * - vérifie si un avoir FNE a déjà été généré avec succès ;
     * - détermine si une facture FNE permet de générer un avoir ;
     * - charge les valeurs par défaut du fichier `config.ini` ;
     * - récupère éventuellement le message d'erreur temporaire
     *   issu d'une tentative d'émission FNE ;
     * - transmet les données nécessaires aux templates Smarty ;
     * - retourne les différents templates constituant l'interface FNE
     *   affichée sur la page de commande.
     *
     * Pour les opérations FNE réussies, les codes HTTP `200` et `201`
     * sont considérés comme des codes de succès.
     *
     * Le message temporaire `fne_error` est associé à l'identifiant
     * de la commande concernée. Après sa lecture, le message et son
     * identifiant sont supprimés du cookie puis celui-ci est écrit
     * afin que la suppression soit effectivement persistée dans
     * le navigateur.
     *
     * Les valeurs `PAYMENT_METHOD` et `TEMPLATE` provenant de
     * `config.ini` servent uniquement de valeurs par défaut pour
     * préselectionner les choix dans l'interface d'émission
     * de la facture.
     *
     * @param array $params
     *        Paramètres transmis par PrestaShop lors de l'exécution
     *        du hook. L'identifiant de commande est attendu dans
     *        `$params['id_order']`.
     *
     * 
     * @return string
     *         HTML généré par les templates FNE affichés sur la page
     *         de détail de la commande, ou une alerte d'erreur
     *         lorsque l'identifiant de commande est absent ou invalide.
     */
    public function hookDisplayAdminOrder($params)
    {
        // Message d'erreur si l'identifiant de commande est absent
        if (empty($params['id_order'])) {
            return '<div class="alert alert-danger" role="alert">
                Impossible d’afficher les informations FNE : identifiant de commande manquant.
            </div>';
        }

        $idOrder = (int)$params['id_order'];

        $order = new Order($idOrder);

        if (!Validate::isLoadedObject($order)) {
            return '<div class="alert alert-danger" role="alert">
                Impossible d’afficher les informations FNE : commande introuvable.
            </div>';
        }

        // 🔹 Logs FNE (TOUJOURS chargés ici)
        $logs = Db::getInstance()->executeS(
            'SELECT *
            FROM '._DB_PREFIX_.'fne_log
            WHERE id_order='.(int)$idOrder.'
            ORDER BY created_at DESC'
        );

        // 🔹 Facture déjà envoyée (succès uniquement)
        $alreadySent = (int)Db::getInstance()->getValue(
            'SELECT COUNT(*)
            FROM '._DB_PREFIX_.'fne_log
            WHERE id_order='.(int)$idOrder.'
            AND type="INVOICE"
            AND response_code IN ("200","201")'
        );

        // 🔹 Avoir déjà généré (succès uniquement)
        $alreadyRefunded = (int)Db::getInstance()->getValue(
            'SELECT COUNT(*)
            FROM '._DB_PREFIX_.'fne_log
            WHERE id_order='.(int)$idOrder.'
            AND type="REFUND"
            AND response_code IN ("200","201")'
        );

        $canRefund = (int)Db::getInstance()->getValue(
            'SELECT COUNT(*)
            FROM '._DB_PREFIX_.'fne_log
            WHERE id_order='.(int)$idOrder.'
            AND type="INVOICE"
            AND response_code IN ("200","201")'
        );


        $config = parse_ini_file( dirname(__FILE__).'/config/config.ini' );

        // >>> AJOUT POINT NCC
        /**
         * Récupère le message d'erreur temporaire généré lors
         * d'une tentative d'émission FNE.
         *
         * Le message est affiché uniquement pour la commande
         * concernée par l'erreur.
         *
         * Après lecture, le message et son identifiant de commande
         * sont supprimés du cookie afin qu'ils ne soient pas
         * réaffichés lors d'une prochaine consultation.
         */
        $fneError = '';

        if (!empty($this->context->cookie->fne_error)) {

            $fneErrorOrderId = (int)$this->context->cookie->fne_error_order_id;

            if ($fneErrorOrderId === $idOrder) {
                $fneError = $this->context->cookie->fne_error;
            }

            // unset($this->context->cookie->fne_error);
            // unset($this->context->cookie->fne_error_order_id);

            // >>> AJOUT POINT NCC - suppression persistante du flash
            unset($this->context->cookie->fne_error);
            unset($this->context->cookie->fne_error_order_id);

            /*
            * Force l'écriture du cookie afin que la suppression
            * soit effectivement répercutée dans le navigateur.
            */
            $this->context->cookie->write();
            // <<< FIN AJOUT POINT NCC
        }
        // <<< FIN AJOUT POINT NCC

        // 🔹 Assignation Smarty (CLAIRE et STABLE)
        $this->context->smarty->assign([
            'id_order'          => $idOrder,
            'sendtofne_url'     => $this->context->link->getAdminLink(
                'AdminSendToFneCi', true, [], ['id_order' => $idOrder]
            ),
            'refund_url'        => $this->context->link->getAdminLink(
                'AdminFneRefund', true, [], ['id_order' => $idOrder]
            ),
            'fne_logs'          => $logs,
            'already_sent'      => ($alreadySent > 0),
            // 'already_refunded'  => ($alreadyRefunded > 0),
            'canRefund' => ($canRefund > 0),
            'already_refunded' => ($alreadyRefunded > 0),

            /*
            * Valeurs par défaut provenant de config.ini.
            * Elles servent uniquement à préselectionner les
            * valeurs dans l'interface d'émission de facture.
            */
            'fne_default_payment_method' => $config['PAYMENT_METHOD'],

            'fne_default_template' => $config['TEMPLATE'],

            'fne_error' => $fneError,
        ]);

        return
            $this->display(__FILE__, 'views/templates/admin/alert.tpl')
            . $this->display(__FILE__, 'views/templates/admin/button.tpl')
            . $this->display(__FILE__, 'views/templates/admin/history.tpl');
    }

    /**
     * Récupère les journaux FNE associés à une commande PrestaShop.
     *
     * La méthode interroge la table `fne_log` et retourne toutes
     * les opérations FNE enregistrées pour la commande indiquée.
     *
     * Les journaux sont classés du plus récent au plus ancien
     * selon leur date de création.
     *
     * Le journal peut notamment contenir les opérations relatives
     * à l'émission d'une facture FNE (`INVOICE`) ou à la génération
     * d'un avoir FNE (`REFUND`), ainsi que leurs réponses respectives.
     *
     * Cette méthode constitue une méthode d'accès aux données du
     * journal FNE et ne modifie aucune donnée en base.
     *
     * @param int $idOrder
     *        Identifiant de la commande PrestaShop.
     *
     * @return array
     *         Tableau des journaux FNE associés à la commande.
     */
    public function getFneLogsForOrder($idOrder)
    {
        return Db::getInstance()->executeS(
            'SELECT *
            FROM '._DB_PREFIX_.'fne_log
            WHERE id_order='.(int)$idOrder.'
            ORDER BY created_at DESC'
        );
    }


}
?>