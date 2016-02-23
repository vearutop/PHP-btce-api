<?php
/**
 * API-call related functions
 *
 * @author marinu666
 * @license MIT License - https://github.com/marinu666/PHP-btce-api
 */
class BTCeAPI {
    
    const DIRECTION_BUY = 'buy';
    const DIRECTION_SELL = 'sell';
    const ORDER_DESC = 'DESC';
    const ORDER_ASC = 'ASC';

    const PAIR_BTC_USD = 'btc_usd';
    const PAIR_BTC_RUR = 'btc_rur';
    const PAIR_BTC_EUR = 'btc_eur';

    const PAIR_LTC_USD = 'ltc_usd';
    const PAIR_LTC_RUR = 'ltc_rur';
    const PAIR_LTC_EUR = 'ltc_eur';

    const PAIR_NMC_BTC = 'nmc_btc';
    const PAIR_NMC_USD = 'nmc_usd';

    const PAIR_NVC_BTC = 'nvc_btc';
    const PAIR_NVC_USD = 'nvc_usd';

    const PAIR_USD_RUR = 'usd_rur';
    const PAIR_EUR_USD = 'eur_usd';

    const PAIR_TRC_BTC = 'trc_btc';
    const PAIR_PPC_BTC = 'ppc_btc';
    const PAIR_FTC_BTC = 'ftc_btc';
    const PAIR_XPM_BTC = 'xpm_btc';


    protected $public_api = 'https://btc-e.com/api/2/';
    
    protected $api_key;
    protected $api_secret;
    protected $noonce;
    protected $RETRY_FLAG = false;
    
    public function __construct($api_key, $api_secret, $base_noonce = false) {
        $this->api_key = $api_key;
        $this->api_secret = $api_secret;
        if($base_noonce === false) {
            // Try 1?
            $this->noonce = time();
        } else {
            $this->noonce = $base_noonce;
        }
    }
    
    /**
     * Get the noonce
     * @global type $sql_conx
     * @return type 
     */
    protected function getnoonce() {
        $this->noonce++;
        return array(0.05, $this->noonce);
    }
    
    /**
     * Call the API
     * @staticvar null $ch
     * @param type $method
     * @param type $req
     * @return type
     * @throws Exception 
     */
    public function apiQuery($method, $req = array()) {
        $req['method'] = $method;
        $mt = $this->getnoonce();
        $req['nonce'] = $mt[1];
       
        // generate the POST data string
        $post_data = http_build_query($req, '', '&');
 
        // Generate the keyed hash value to post
        $sign = hash_hmac("sha512", $post_data, $this->api_secret);
 
        // Add to the headers
        $headers = array(
                'Sign: '.$sign,
                'Key: '.$this->api_key,
        );
 
        // Create a CURL Handler for use
        $ch = null;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; Marinu666 BTCE PHP client; '.php_uname('s').'; PHP/'.phpversion().')');
        curl_setopt($ch, CURLOPT_URL, 'https://btc-e.com/tapi/');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
 
        // Send API Request
        $res = curl_exec($ch);        
        
        // Check for failure & Clean-up curl handler
        if($res === false) {
            $e = curl_error($ch);
            curl_close($ch);
            throw new BTCeAPIFailureException('Could not get reply: '.$e);
        } else {
            curl_close($ch);
        }
        
        // Decode the JSON
        $result = json_decode($res, true);
        // is it valid JSON?
        if(!$result) {
            throw new BTCeAPIInvalidJSONException('Invalid data received, please make sure connection is working and requested API exists');
        }
        
        // Recover from an incorrect noonce
        if(isset($result['error']) === true) {
            if(strpos($result['error'], 'nonce') > -1 && $this->RETRY_FLAG === false) {
                $matches = array();
                $k = preg_match('/:([0-9]+),/', $result['error'], $matches);
                $this->RETRY_FLAG = true;
                trigger_error("Nonce we sent ({$this->noonce}) is invalid, retrying request with server returned nonce: ({$matches[1]})!");
                $this->noonce = $matches[1];
                return $this->apiQuery($method, $req);
            } else {
                throw new BTCeAPIErrorException('API Error Message: '.$result['error'].". Response: ".print_r($result, true));
            }
        }
        // Cool -> Return
        $this->RETRY_FLAG = false;
        return $result;
    }
    
    /**
     * Retrieve some JSON
     * @param type $URL
     * @return type 
     */
    protected function retrieveJSON($URL) {
        $opts = array('http' =>
            array(
                'method'  => 'GET',
                'timeout' => 10 
            )
        );
        $context  = stream_context_create($opts);
        $feed = file_get_contents($URL, false, $context);
        $json = json_decode($feed, true);
        return $json;
    }
    
    /**
     * Place an order
     * @param type $amount
     * @param type $pair
     * @param type $direction
     * @param type $price
     * @return type 
     */
    public function makeOrder($amount, $pair, $direction, $price) {
        if($direction == self::DIRECTION_BUY || $direction == self::DIRECTION_SELL) {
            $data = $this->apiQuery("Trade"
                    ,array(
                        'pair' => $pair,
                        'type' => $direction,
                        'rate' => $price,
                        'amount' => $amount
                    )
            );
            return $data; 
        } else {
            throw new BTCeAPIInvalidParameterException('Expected constant from '.__CLASS__.'::DIRECTION_BUY or '.__CLASS__.'::DIRECTION_SELL. Found: '.$direction);
        }
    }


    public function getInfo() {
        $data = $this->apiQuery("getInfo");
        return $data;
    }

    public function getInfoData() {
        $info = $this->getInfo();
        if ($info['success'] === 1) {
            return $info['return'];
        } else {
            throw new BTCeAPIFailureException($info['error']);
        }
    }


    public function transHistory($offset = 0, $count = 1000, $fromId = 0, $endId = null, $order = self::ORDER_DESC, $sinceUt = 0, $endUt = null) {
        $params = array(
            'from' => $offset,
            'count' => $count,
            'from_id' => $fromId,
            'end_id' => $endId,
            'order' => $order,
            'since' => $sinceUt,
            'end' => $endUt
        );
        foreach ($params as $k => $v) {
            if (null === $v) {
                unset($params[$k]);
            }
        }

        $data = $this->apiQuery("TransHistory", $params);
        return $data;
    }


    public function tradeHistory($offset = 0, $count = 1000, $fromId = 0, $endId = null, $order = self::ORDER_DESC, $sinceUt = 0, $endUt = null, $pair = null) {
        $params = array(
            'from' => $offset,
            'count' => $count,
            'from_id' => $fromId,
            'end_id' => $endId,
            'order' => $order,
            'since' => $sinceUt,
            'end' => $endUt,
            'pair' => $pair
        );
        foreach ($params as $k => $v) {
            if (null === $v) {
                unset($params[$k]);
            }
        }

        $data = $this->apiQuery("TradeHistory", $params);
        return $data;
    }


    public function activeOrders($pair = null) {
        $params = array(
            'pair' => $pair
        );
        foreach ($params as $k => $v) {
            if (null === $v) {
                unset($params[$k]);
            }
        }

        $data = $this->apiQuery("ActiveOrders", $params);
        return $data;
    }

    public function cancelOrder($orderId) {
        $params = array(
            'order_id' => $orderId
        );
        foreach ($params as $k => $v) {
            if (null === $v) {
                unset($params[$k]);
            }
        }

        $data = $this->apiQuery("CancelOrder", $params);
        return $data;
    }



    /**
     * Cancel an order
     * @param type $order_id
     * @return type 
     */
    public function cancelOrder($order_id) {
        return $this->apiQuery("CancelOrder"
                    ,array(
                        'order_id' => $order_id
                    )
               );
    }
    
    /**
     * Check an order that is complete (non-active)
     * @param type $orderID
     * @return type
     * @throws Exception 
     */
    public function checkPastOrder($orderID) {
        $data = $this->apiQuery("OrderList"
                ,array(
                    'from_id' => $orderID,
                    'to_id' => $orderID,
                    /*'count' => 15,*/
                    'active' => 0
                ));
        if($data['success'] == "0") {
            throw new BTCeAPIErrorException("Error: ".$data['error']);
        } else {
            return($data);
        }
    }
    
    /**
     * Public API: Retrieve the Fee for a currency pair
     * @param string $pair
     * @return array 
     */
    public function getPairFee($pair) {
        return $this->retrieveJSON($this->public_api.$pair."/fee");
    }
    
    /**
     * Public API: Retrieve the Ticker for a currency pair
     * @param string $pair
     * @return array 
     */
    public function getPairTicker($pair) {
        return $this->retrieveJSON($this->public_api.$pair."/ticker");
    }
    
    /**
     * Public API: Retrieve the Trades for a currency pair
     * @param string $pair
     * @return array 
     */
    public function getPairTrades($pair) {
        return $this->retrieveJSON($this->public_api.$pair."/trades");
    }
    
    /**
     * Public API: Retrieve the Depth for a currency pair
     * @param string $pair
     * @return array 
     */
    public function getPairDepth($pair) {
        return $this->retrieveJSON($this->public_api.$pair."/depth");
    }

}

/**
 * Exceptions
 */
class BTCeAPIException extends Exception {}
class BTCeAPIFailureException extends BTCeAPIException {}
class BTCeAPIInvalidJSONException extends BTCeAPIException {}
class BTCeAPIErrorException extends BTCeAPIException {}
class BTCeAPIInvalidParameterException extends BTCeAPIException {}
?>
