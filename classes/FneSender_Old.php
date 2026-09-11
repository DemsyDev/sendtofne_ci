<?php
class FneSender
{
    private $cfg;
    private $baseUrl;
    private $apiKey;

    public function __construct()
    {
        $this->cfg = parse_ini_file(dirname(__FILE__).'/../config/config.ini');
        $this->baseUrl = rtrim($this->cfg['FNE_BASE_URL'], '/');
        $this->apiKey = trim($this->cfg['FNE_API_KEY']);
    }

    public function sendInvoice(Order $order)
    {
        return $this->call('/external/invoices/sign', $this->buildInvoicePayload($order));
    }

    public function sendRefund($invoiceId, $items = [])
    {
        return $this->call('/external/invoices/'.$invoiceId.'/refund', ['items'=>$items]);
    }

    private function buildInvoicePayload(Order $order)
    {
        $customer = new Customer($order->id_customer);

        // SVO2.4 – Hiérarchie Société Client validée
        $invoiceAddress = new Address($order->id_address_invoice);

        // 3️⃣ Fallback final : Prénom Nom
        $clientCompanyName = trim($customer->firstname . ' ' . $customer->lastname);

        // 2️⃣ Société sur l’adresse de facturation
        if (Validate::isLoadedObject($invoiceAddress) && !empty($invoiceAddress->company)) {
            /* Pour éviter que PDV UnivPat soit affiché comme nom du client sur le PDF FNE */
            if ( trim($invoiceAddress->company) == 'PDV UnivPat' ) {
                $clientCompanyName = trim($customer->firstname . ' ' . $customer->lastname);
            }
            else{
                $clientCompanyName = trim($invoiceAddress->company);
            }
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

        return [
            "invoiceType"=>"sale",
            "paymentMethod"=>$this->cfg['PAYMENT_METHOD'],
            "template"=>$this->cfg['TEMPLATE'],
            "isRne"=>false,
            "rne"=>null,
            "clientNcc"=> $ncc,
            "clientPhone"=> $clientPhone ?: "0000000000",
            // "clientCompanyName"=>$customer->firstname.' '.$customer->lastname,
            "clientCompanyName"=>$clientCompanyName,
            "clientEmail"=>$customer->email,
            "clientSellerName"=>$this->cfg['CLIENT_SELLER_NAME'],
            "pointOfSale"=>$this->cfg['POINT_OF_SALE'],
            "establishment"=>$this->cfg['ESTABLISHMENT'],
            "commercialMessage"=>"Univers du pâtissier - Produits et matériels de pâtisserie",
            "footer"=>"FNE CI",
            "foreignCurrency"=>"",
            "foreignCurrencyRate"=>0,
            "items"=>$items,
            "customTaxes"=>[],
            "discount"=>0
        ];
    }
    
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