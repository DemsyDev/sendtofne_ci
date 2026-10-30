
<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_
    . 'sendtofne_ci/classes/FneInvoiceStatesService.php';

/**
 * Page Back Office listant les commandes ayant une facture FNE réussie.
 *
 * La page est en lecture seule : elle ne modifie ni les commandes,
 * ni les états PrestaShop, ni l'historique FNE.
 *
 * @author Adamodial
 */
class AdminFneInvoiceStatesController extends ModuleAdminController
{
    /**
     * Initialise le contrôleur.
     */
    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();

        $this->meta_title = $this->l('Commandes FNE');
    }

    
    /**
     * Charge les données et affiche la liste des commandes FNE.
     */
    public function initContent()
    {
        $service = new FneInvoiceStatesService();

        $orders = $service->getOrdersWithSuccessfulFneInvoice();

        // Préparation des liens sécurisés vers les fiches de commande.
        foreach ($orders as &$order) {
            $order['order_url'] = $this->context->link->getAdminLink(
                'AdminOrders',
                true,
                [],
                [
                    'id_order' => (int) $order['id_order'],
                    'vieworder' => 1,
                ]
            );
        }
        unset($order);

        $this->context->smarty->assign([
            'fne_orders' => $orders,
            'fne_orders_count' => count($orders),
        ]);

        $templatePath = _PS_MODULE_DIR_
            . $this->module->name
            . '/views/templates/admin/fne_invoice_states.tpl';

        $this->content = $this->context->smarty->fetch($templatePath);

        parent::initContent();
    }

}
