<?php

use Infinex\Exceptions\Error;
use React\Promise;

class TransactionsAPI {
    private $log;
    private $amqp;
    private $transactions;
    private $networks;
    
    private $allowedStatus = [
        'PENDING',
        'PROCESSING',
        'CONFIRM_PENDING',
        'DONE',
        'CANCEL_PENDING',
        'CANCELED'
    ];
    
    function __construct($log, $amqp, $transactions, $networks) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> transactions = $transactions;
        $this -> networks = $networks;
        
        $this -> log -> debug('Initialized transactions API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/transactions', [$this, 'getAllTransactions']);
        $rc -> get('/transactions/{xid}', [$this, 'getTransaction']);
        $rc -> delete('/transactions/{xid}', [$this, 'cancelTransaction']);
        $rc -> post('/transactions', [$this, 'createTransaction']);
    }
    
    public function getAllTransactions($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $promise = [];
        
        if(isset($query['asset']))
            $promise = $this -> amqp -> call(
                'wallet.wallet',
                'getAsset',
                [ 'symbol' => $query['asset'] ]
            );
        else
            $promise = Promise\resolve(null);
        
        return $promise -> then(function($asset) use($th, $query, $auth) {
            if(isset($query['network'])) {
                $network = $th -> networks -> getNetwork([
                    'symbol' => $query['network']
                ]);
            }
            else
                $network = null;
            
            if(isset($query['status'])) {
                $status = [];
                
                $expStatus = explode(',', $query['status']);
                foreach($expStatus as $statusItem) {
                    if(!in_array($statusItem, $th -> allowedStatus))
                        throw new Error(
                            'VALIDATION_ERROR',
                            'Allowed statuses: '.implode(', '.$th -> allowedStatus),
                            400
                        );
                    
                    $status[] = $statusItem;
                }
            }
            else
                $status = $th -> allowedStatus;
                
            $resp = $th -> transactions -> getTransactions([
                'uid' => $auth['uid'],
                'assetid' => @$asset['assetid'],
                'netid' => @$network['netid'],
                'type' => isset($query['type']) ? explode(',', $query['type']) : null,
                'status' => $status,
                'offset' => @$query['offset'],
                'limit' => @$query['limit']
            ]);
            
            $promises = [];
            $mapAssets = [];
            
            foreach($resp['transactions'] as $record) {
                $assetid = $record['assetid'];
                
                if(!array_key_exists($assetid, $mapAssets)) {
                    $mapAssets[$assetid] = null;
                    
                    $promises[] = $th -> amqp -> call(
                        'wallet.wallet',
                        'getAsset',
                        [ 'assetid' => $assetid ]
                    ) -> then(
                        function($asset) use(&$mapAssets, $assetid) {
                            $mapAssets[$assetid] = $asset;
                        }
                    );
                }
            }
            
            return Promise\all($promises) -> then(
                function() use(&$mapAssets, $resp, $th) {
                    foreach($resp['transactions'] as $k => $v)
                        $resp['transactions'][$k] = $th -> ptpTransaction($v, $mapAssets[ $v['assetid'] ]);
                    
                    return $resp;
                }
            );
        });
    }
    
    public function getTransaction($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $transaction = $th -> transactions -> getTransaction([
            'xid' => $path['xid'],
            'uid' => $auth['uid']
        ]);
        
        if(
            $transaction['uid'] != $auth['uid'] ||
            !in_array($transaction['status'], $this -> allowedStatus)
        )
            throw new Error('FORBIDDEN', 'No permissions to transaction '.$path['xid'], 403);
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'getAsset',
            [ 'assetid' => $transaction['assetid'] ]
        ) -> then(
            function($asset) use($th, $transaction) {
                return $th -> ptpTransaction($transaction, $asset);
            }
        );
    }
    
    private function ptpTransaction($record, $asset) {
        if($record['netid']) {
            $network = $this -> networks -> getNetwork([
                'netid' => $record['netid']
            ]);
        }
        else
            $network = null;
        
        return [
            'xid' => $record['xid'],
            'type' => $record['type'],
            'amount' => $record['amount'],
            'status' => $record['status'],
            'createTime' => $record['createTime'],
            'address' => $record['address'],
            'memo' => $record['memo'],
            'execTime' => $record['execTime'],
            'confirmations' => $record['confirmations'], // TODO
            'confirmTarget' => $record['confirmTarget'],
            'txid' => $record['txid'],
            'height' => $record['height'],
            'fee' => $record['wdFeeThis'],
            'asset' => [
                'symbol' => $asset['symbol'],
                'name' => $asset['name'],
                'iconUrl' => $asset['iconUrl']
            ],
            'network' => $network ? [
                'symbol' => $network['symbol'],
                'name' => $network['name'],
                'iconUrl' => $network['iconUrl'],
                'memoName' => $network['memoName']
            ] : null
        ];
    }
}

?>