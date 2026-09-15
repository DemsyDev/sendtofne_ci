<?php
/**
 * Envoi des factures et avoirs vers l'API FNE de la DGI Côte d'Ivoire.
 *
 * Cette classe constitue la couche technique de communication
 * entre PrestaShop et l'API FNE.
 *
 * Les paramètres PAYMENT_METHOD et TEMPLATE présents dans config.ini
 * servent de valeurs par défaut pour l'interface d'émission de facture.
 *
 * Le PAYMENT_METHOD et le TEMPLATE effectivement utilisés pour une
 * facture sont désormais fournis explicitement à sendInvoice().
 *
 * La génération d'un avoir reste indépendante de ces paramètres.
 */
class FneSender
{
    /**
     * Configuration technique du module.
     *
     * @var array
     */
    private $cfg;

    /**
     * URL de base de l'API FNE.
     *
     * @var string
     */
    private $baseUrl;

    /**
     * Clé d'authentification de l'API FNE.
     *
     * @var string
     */
    private $apiKey;

    /**
     * Initialise le client FNE.
     *
     * Les valeurs PAYMENT_METHOD et TEMPLATE sont conservées
     * dans la configuration uniquement comme valeurs par défaut.
     */
    public function __construct()
    {
        $this->cfg = parse_ini_file(dirname(__FILE__).'/../config/config.ini');
        $this->baseUrl = rtrim($this->cfg['FNE_BASE_URL'], '/');
        $this->apiKey = trim($this->cfg['FNE_API_KEY']);
    }

    /**
     * Envoie une facture à la FNE.
     *
     * Le PAYMENT_METHOD et le TEMPLATE sont déterminés
     * par l'interface d'émission de la facture.
     *
     * @param Order  $order         Commande PrestaShop.
     * @param string $paymentMethod Mode de paiement FNE.
     * @param string $template      Template FNE.
     *
     * @return array
     */
    public function sendInvoice(Order $order, $paymentMethod, $template)
    {
        return $this->call('/external/invoices/sign', $this->buildInvoicePayload($order, $paymentMethod, $template));
    }

    /**
     * Envoie un avoir à la FNE.
     *
     * Cette opération est indépendante du PAYMENT_METHOD
     * et du TEMPLATE utilisés lors de l'émission de la facture.
     *
     * @param string $invoiceId ID technique de la facture FNE.
     * @param array  $items     Lignes FNE à annuler.
     *
     * @return array
     */
    public function sendRefund($invoiceId, $items = [])
    {
        return $this->call('/external/invoices/'.$invoiceId.'/refund', ['items'=>$items]);
    }

    /**
     * Construit le payload d'une facture FNE.
     *
     * @param Order  $order         Commande PrestaShop.
     * @param string $paymentMethod Mode de paiement FNE sélectionné.
     * @param string $template      Template FNE sélectionné.
     *
     * @return array
     */
    private function buildInvoicePayload(Order $order, $paymentMethod, $template)
    {
        $customer = new Customer($order->id_customer);

        // var_dump($order);die();

        // echo '<pre>';
        /** Méthode 1 — print_r + <pre> (rapide et efficace) */
        // print_r($order);

        /**
         * Méthode 2 — La méthode PRO : convertir l’ObjectModel en tableau
         * Les objets PrestaShop héritent de ObjectModel.
         * Ils ont une méthode interne faite exactement pour ça :
         */
        // print_r($order->getFields());

        /** Méthode 3 — Le rendu le plus propre : JSON pretty print 
         * C’est la méthode la plus lisible pour analyser une commande.
        */
        // echo json_encode($order->getFields(), JSON_PRETTY_PRINT);

        // Astuce PrestaShop très utile (peu connue) - Pour voir exactement ce qui vient de la base :
        // print_r($order->getFields());
        // print_r($order->getFieldsLang());

        // echo '</pre>';
        // die();

        // $order = new Order("40494");
        /** Diagnostic pour la remise */
            /*
            $order = new Order("36851");

            $cartRules = $order->getCartRules();

            echo '<pre>';

            print_r($cartRules);

            foreach ($cartRules as $cartRuleData) {
                $cartRule = new CartRule((int)$cartRuleData['id_cart_rule']);

                echo "\n==============================\n";
                echo "CartRule ID : ".$cartRule->id."\n";
                echo "Nom         : ".$cartRuleData['name']."\n";
                echo "Valeur TTC  : ".$cartRuleData['value']."\n";
                echo "Valeur HT   : ".$cartRuleData['value_tax_excl']."\n";
                echo "Réduction % : ".$cartRule->reduction_percent."\n";
                echo "Réduction montant : ".$cartRule->reduction_amount."\n";
                echo "Réduction taxe : ".($cartRule->reduction_tax ? 'OUI' : 'NON')."\n";
            }

            echo '</pre>';
            die;*/
        // /** //.Diagnostic pour la remise */

        /* Vérification de la cohérence Total Prestashop et FNE

            $order = new Order(40494);

            if (!Validate::isLoadedObject($order)) {
                die('Commande invalide');
            }

            $products = $order->getProducts();

            $totalFneLines = 0.0;

            echo '<pre>';

            echo "==============================\n";
            echo "COHERENCE TOTAL PRODUITS / FNE\n";
            echo "==============================\n\n";

            echo "PrestaShop total_products : ";
            var_dump((float)$order->total_products);

            echo "\n";
            echo "LIGNES DE COMMANDE\n";
            echo "------------------------------\n";

            foreach ($products as $p) {

                $quantity = (float)$p['product_quantity'];
                $unitPrice = (float)$p['unit_price_tax_excl'];

                $lineTotal = $unitPrice * $quantity;

                $totalFneLines += $lineTotal;

                echo "\n";
                echo "Produit       : ".$p['product_name']."\n";
                echo "Référence     : ".$p['product_reference']."\n";
                echo "Quantité      : ".$quantity."\n";
                echo "Prix unitaire : ".$unitPrice." F\n";
                echo "Total ligne   : ".$lineTotal." F\n";
            }

            echo "\n";
            echo "==============================\n";
            echo "RESULTAT\n";
            echo "==============================\n";

            echo "total_products PrestaShop : ".$order->total_products." F\n";
            echo "Somme lignes FNE          : ".$totalFneLines." F\n";

            $ecart = $totalFneLines - (float)$order->total_products;

            echo "Ecart                      : ".$ecart." F\n";

            echo "\n";

            if (abs($ecart) < 0.01) {
                echo "OK : COHERENCE CONFIRMEE\n";
            } else {
                echo "ATTENTION : ECART DETECTE\n";
            }

            echo '</pre>';

            die;

        */


        // SVO2.4 – Hiérarchie Société Client validée
        $invoiceAddress = new Address($order->id_address_invoice);

        // 3️⃣ Fallback final : Prénom Nom
        $clientCompanyName = trim($customer->firstname . ' ' . $customer->lastname);

        // 2️⃣ Société sur l’adresse de facturation
        if (Validate::isLoadedObject($invoiceAddress) && !empty($invoiceAddress->company)) {

            /*
             * Pour éviter que PDV UnivPat soit affiché comme
             * nom du client sur le PDF FNE.
             */
            if ( trim($invoiceAddress->company) == 'PDV UnivPat') {
                $clientCompanyName = trim($customer->firstname.' '.$customer->lastname);
            } else {
                $clientCompanyName = trim($invoiceAddress->company);
            }
            // $clientCompanyName = trim($invoiceAddress->company);
        }

        // 1️⃣ Société sur la fiche client (priorité maximale)
        if (Validate::isLoadedObject($customer) && !empty($customer->company)) {
            $clientCompanyName = trim($customer->company);
        }

        //../SVO2.4 – Hiérarchie Société Client validée


        $invoiceAddress = new Address($order->id_address_invoice);

        // NCC = SIRET CLIENT (niveau client)
        $ncc = null;
        if (Validate::isLoadedObject($customer) && !empty($customer->siret)) {
            $ncc = trim($customer->siret);
            // var_dump(($ncc));die();
        }

        //Téléphone client
        $clientPhone = null;
        if (Validate::isLoadedObject($invoiceAddress)) {
            if (!empty($invoiceAddress->phone_mobile)) {
                $clientPhone = trim($invoiceAddress->phone_mobile);
            } elseif (!empty($invoiceAddress->phone)) {
                $clientPhone = trim($invoiceAddress->phone);
            }
        }

        $items = [];

        foreach ($order->getProducts() as $p) {
            $items[] = [
                "taxes"=>["TVAC"],
                "customTaxes"=>[],
                "reference"=>$p['product_reference'] ?: 'P'.$p['product_id'],
                "description"=>$p['product_name'],
                "quantity"=>(float)$p['product_quantity'],
                "amount"=>(float)$p['unit_price_tax_excl'],
                "discount"=>0,
                "measurementUnit"=>"pcs"
            ];
        }

        // >>> AJOUT POINT 31
        /**
         * Calcule le taux de remise globale à transmettre
         * à la FNE.
         *
         * Le champ "discount" du payload FNE est exprimé
         * sous forme de pourcentage et est appliqué par la FNE
         * au total avant remise.
         *
         * Le montant réellement remisé est récupéré depuis
         * la commande PrestaShop via total_discounts.
         *
         * Les remises déjà intégrées dans le prix unitaire
         * des lignes produits ne sont pas recalculées ici.
         *
         * @param Order $order Commande PrestaShop.
         *
         * @return float Taux de remise globale à transmettre à la FNE.
         */
        $discount = $this->getOrderFneDiscount($order);
        // <<< FIN AJOUT POINT 31

        return [
            "invoiceType"=>"sale",
            // "paymentMethod"=>$this->cfg['PAYMENT_METHOD'],
            // "template"=>$this->cfg['TEMPLATE'],
            
            /* Ces deux valeurs proviennent maintenant de l'interface d'émission de la facture. */
            'paymentMethod' => $paymentMethod,
            'template' => $template,
            "isRne"=>false,
            "rne"=>null,
            "clientNcc"=> $ncc,
            "clientPhone"=> $clientPhone ?: "0000000000",
            "clientCompanyName"=>$clientCompanyName,
            "clientEmail"=>$customer->email,
            "clientSellerName"=>$this->cfg['CLIENT_SELLER_NAME'],
            "pointOfSale"=>$this->cfg['POINT_OF_SALE'],
            "establishment"=>$this->cfg['ESTABLISHMENT'],
            // "commercialMessage"=> 'Réf.Int.Cmde: '.$order->id_order,
            "commercialMessage"=> 'Réf.Int.Cmde: '.$order->reference,
            "footer"=>"Univers du pâtissier - Produits et matériels de pâtisserie",
            "foreignCurrency"=>"",
            "foreignCurrencyRate"=>0,
            "items"=>$items,
            "customTaxes"=>[],
            
            // >>> AJOUT POINT 31
            "discount"=>$discount
            // <<< FIN AJOUT POINT 31
        ];
    }

    // >>> AJOUT POINT 31
    /**
     * Calcule le taux de remise globale à transmettre
     * à la FNE pour une commande PrestaShop.
     *
     * Le montant de remise est récupéré depuis
     * total_discounts et rapporté à total_products.
     *
     * Formule :
     *
     *     total_discounts / total_products × 100
     *
     * Exemple :
     *
     *     total_products  = 24425
     *     total_discounts = 4425
     *
     *     => 18.1167 %
     *
     * Cette méthode concerne uniquement la remise globale
     * de la commande.
     *
     * Une remise directement appliquée au prix d'un produit
     * par le POS est déjà intégrée dans unit_price_tax_excl
     * et ne doit donc pas être appliquée une seconde fois
     * au niveau de items[].discount.
     *
     * @param Order $order Commande PrestaShop.
     *
     * @return float Taux de remise globale.
     */
    private function getOrderFneDiscount(Order $order)
    {
        $totalProducts = (float)$order->total_products;
        $totalDiscounts = (float)$order->total_discounts;

        if ($totalProducts <= 0 || $totalDiscounts <= 0) {
            return 0.0;
        }

        $discount = ($totalDiscounts / $totalProducts) * 100;

        return round($discount, 4);
    }
    // <<< FIN AJOUT POINT 31
    
    /**
     * Effectue un appel HTTP POST vers l'API FNE.
     *
     * @param string $endpoint Endpoint relatif de l'API.
     * @param array  $payload  Données JSON à transmettre.
     *
     * @return array
     */
    private function call($endpoint, $payload)
    {
        $ch = curl_init($this->baseUrl.$endpoint);

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer '.$this->apiKey
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [
                'code' => 0,
                'body' => null,
                'invoice_id' => null,
                'headers' => null,
                'error' => $error
            ];
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headersRaw = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // 🔎 Analyse ligne par ligne des headers
        $invoiceId = null;
        foreach (explode("\n", $headersRaw) as $line) {
            $line = trim($line);

            // Capture UUID depuis Location ABSOLUE ou RELATIVE
            if (stripos($line, 'location:') === 0) {
                if (preg_match('#/external/invoices/([a-f0-9\-]{36})#i', $line, $m)) {
                    $invoiceId = $m[1];
                    break;
                }
            }

            // Fallback éventuel (certains serveurs)
            if (stripos($line, 'x-resource-id:') === 0) {
                $invoiceId = trim(substr($line, 14));
                break;
            }
        }

        return [
            'code' => (int)$code,
            'body' => $body,
            'invoice_id' => $invoiceId,
            'headers' => $headersRaw
        ];
    }

    /**
     * Récupère les détails d'une facture FNE.
     *
     * @param string $invoiceId ID technique FNE.
     *
     * @return array
     */
    public function getInvoiceDetails($invoiceId)
    {
        // ⚠️ invoiceId = ID technique (UUID), PAS la référence FNE
        $url = $this->baseUrl . '/external/invoices/' . rawurlencode($invoiceId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ],
            CURLOPT_RETURNTRANSFER => true
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'code' => (int)$code,
            'body' => $body
        ];
    }

}
?>