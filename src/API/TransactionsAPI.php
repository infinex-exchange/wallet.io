<?php

use Infinex\Exceptions\Error;

class WithdrawalAPI {
    private $log;
    private $amqp;
    private $transactions;
    private $networks;
    
    function __construct($log, $amqp, $transactions, $networks) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> transactions = $transactions;
        $this -> networks = $networks;
        
        $this -> log -> debug('Initialized withdrawal API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/transactions', [$this, 'getAllTransactions']);
        $rc -> get('/transactions/{xid}', [$this, 'getTransaction']);
        $rc -> post('/transactions', [$this, 'createTransaction']);
    }
    
    public function getAllTransactions($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        if(isset($query['asset']))
            $promise = $this -> amqp -> call(
                'wallet.wallet',
                'symbolToAssetId',
                [
                    'symbol' => $query['asset'],
                    'allowDisabled' => false
                ]
            );
        else
            $promise = Promise\resolve(null);
        
        return $promise -> then(function($assetid) use($th, $query, $auth) {
            if(isset($query['network']))
                $netid = $th -> networks -> symbolToNetId($query['network'], false);
            else
                $netid = null;
                
            $body = [
                'uid' => $auth['uid'],
                'assetid' => $assetid,
                'type' => isset($query['type']) ? $query['type'] : null,
                'limit' => isset($query['limit']) ? $query['limit'] : null,
                'offset' => isset($query['offset']) ? $query['offset'] : null,
                'netid' => $netid
            ];
            
            return $th -> transactions -> getTransactions($body) -> then(
                function($resp) use($th) {
                    $promises = [];
                    
                    foreach($resp['transactions'] as $privTx)
                        $promises[] = $th -> privTxToPubTxRecord($privTx);
                    
                    return Promise\all($promises) -> then(
                        function($pubTxs) use($resp) {
                            return [
                                'transactions' => $pubTxs,
                                'more' => $resp['more']
                            ];
                        }
                    );
                }
            );
        });
    }
    
    public function getTransaction($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        return $th -> transactions -> getTransaction([
            'xid' => $path['xid']
        ]) -> then(
            function($tx) use($th) {
                return $th -> privTxToPubTxRecord($tx);
            }
        );
    }
    
    private function privTxToPubTxRecord($priv) {
        $th = $this;
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'assetIdToSymbol',
            [ 'assetid' => $priv['assetid'] ]
        ) -> then(function($assetSymbol) use($th, $priv) {
            if($priv['netid'])
                $netSymbol = $th -> networks -> netIdToSymbol($priv['netid']);
            else
                $netSymbol = null;
            
            return [
                'xid' => $priv['xid'],
                'asset' => $assetSymbol,
                'network' => $netSymbol
            ];
        });
    }
}

?>