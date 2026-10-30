
<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Lecture de l'historique FNE et détermination de l'état FNE courant.
 *
 * Le journal fne_log est la source de référence pour l'état FNE.
 * Les tentatives en échec ne participent jamais à la détermination
 * de l'état ni à la sélection des références affichées.
 *
 * @author Adamodial
 */
class FneInvoiceStatesService
{
    /**
     * Codes HTTP considérés comme des succès par l'intégration FNE.
     *
     * @var array
     */
    private $successResponseCodes = ['200', '201'];

    /**
     * Retourne les commandes possédant au moins une facture FNE réussie.
     *
     * Pour chaque commande, la méthode fournit :
     * - la référence PrestaShop ;
     * - la référence de la facture FNE réussie la plus récente ;
     * - la référence du dernier avoir FNE réussi ;
     * - l'état FNE correspondant à la dernière opération réussie ;
     * - la date de cette dernière opération réussie.
     *
     * Les journaux ne sont ni supprimés ni modifiés.
     *
     * @return array
     */
    public function getOrdersWithSuccessfulFneInvoice()
    {
        $sql = '
            SELECT
                o.id_order,
                o.reference AS order_reference,
                o.date_add AS order_date,
                l.id_fne_log,
                l.type,
                l.response_code,
                l.response_body,
                l.created_at
            FROM `' . _DB_PREFIX_ . 'orders` o
            INNER JOIN `' . _DB_PREFIX_ . 'fne_log` l
                ON l.id_order = o.id_order
            WHERE l.response_code IN ("200", "201")
              AND EXISTS (
                  SELECT 1
                  FROM `' . _DB_PREFIX_ . 'fne_log` li
                  WHERE li.id_order = o.id_order
                    AND li.type = "INVOICE"
                    AND li.response_code IN ("200", "201")
              )
            ORDER BY
                o.date_add DESC,
                o.id_order DESC,
                l.created_at DESC,
                l.id_fne_log DESC
        ';

        $rows = Db::getInstance()->executeS($sql);

        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $orders = [];

        foreach ($rows as $row) {
            $idOrder = (int) $row['id_order'];

            /*
             * La requête renvoie uniquement les opérations réussies.
             * Comme elles sont triées de la plus récente à la plus ancienne
             * pour chaque commande, la première entrée rencontrée détermine
             * l'état FNE courant.
             */
            if (!isset($orders[$idOrder])) {
                $orders[$idOrder] = [
                    'id_order' => $idOrder,
                    'order_reference' => (string) $row['order_reference'],
                    'order_date' => (string) $row['order_date'],
                    'invoice_reference' => '',
                    'refund_reference' => '',
                    'state_label' => '',
                    'last_event_date' => (string) $row['created_at'],
                    '_latest_event_found' => false,
                    '_invoice_found' => false,
                    '_refund_found' => false,
                ];
            }

            $responseBody = json_decode(
                (string) $row['response_body'],
                true
            );

            if (!is_array($responseBody)) {
                $responseBody = [];
            }

            $type = (string) $row['type'];

            /*
             * La première opération réussie rencontrée est la plus récente.
             * Les tentatives échouées ont déjà été exclues par SQL.
             */
            if (!$orders[$idOrder]['_latest_event_found']) {
                $orders[$idOrder]['state_label'] =
                    ($type === 'REFUND')
                        ? 'Avoir FNE émis'
                        : 'Facture FNE émise';

                $orders[$idOrder]['last_event_date'] =
                    (string) $row['created_at'];

                $orders[$idOrder]['_latest_event_found'] = true;
            }

            /*
             * Référence d'une facture :
             * response_body.invoice.reference
             */
            if (
                $type === 'INVOICE'
                && !$orders[$idOrder]['_invoice_found']
            ) {
                $invoice = isset($responseBody['invoice'])
                    && is_array($responseBody['invoice'])
                    ? $responseBody['invoice']
                    : [];

                if (
                    isset($invoice['reference'])
                    && is_scalar($invoice['reference'])
                ) {
                    $orders[$idOrder]['invoice_reference'] =
                        (string) $invoice['reference'];
                }

                $orders[$idOrder]['_invoice_found'] = true;
            }

            /*
             * Référence d'un avoir :
             * response_body.reference
             */
            if (
                $type === 'REFUND'
                && !$orders[$idOrder]['_refund_found']
            ) {
                if (
                    isset($responseBody['reference'])
                    && is_scalar($responseBody['reference'])
                ) {
                    $orders[$idOrder]['refund_reference'] =
                        (string) $responseBody['reference'];
                }

                $orders[$idOrder]['_refund_found'] = true;
            }
        }

        /*
         * Retrait des propriétés techniques internes.
         */
        foreach ($orders as &$order) {
            unset(
                $order['_latest_event_found'],
                $order['_invoice_found'],
                $order['_refund_found']
            );
        }
        unset($order);

        /*
         * Tri des commandes selon la dernière activité FNE,
         * de la plus récente à la plus ancienne.
         */
        uasort($orders, function ($a, $b) {
            $dateComparison = strcmp(
                $b['last_event_date'],
                $a['last_event_date']
            );

            if ($dateComparison !== 0) {
                return $dateComparison;
            }

            return $b['id_order'] <=> $a['id_order'];
        });

        return array_values($orders);
    }
}
