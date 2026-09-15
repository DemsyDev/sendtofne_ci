<?php
if (!defined('_PS_VERSION_')) { exit; }

class Sendtofne_ci extends Module
{
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

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayAdminOrder')
            && $this->installDb()
            && $this->installOrderState();
            // && $this->installFneRefundOrderState();
    }

    private function installDb()
    {
        $sql = file_get_contents(dirname(__FILE__).'/sql/install.sql');
        return Db::getInstance()->execute($sql);
    }

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

    public function hookDisplayAdminOrder($params)
    {
        if (empty($params['id_order'])) {
            return '';
        }

        $idOrder = (int)$params['id_order'];

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