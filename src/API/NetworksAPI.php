<?php

use Infinex\Exceptions\Error;
use React\Promise;

class NetworksAPI {
    private $log;
    private $pdo;
    private $networks;
    
    function __construct($log, $amqp, $pdo, $networks) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> networks = $networks;
        
        $this -> log -> debug('Initialized networks API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/networks', [$this, 'getAllNetworks']);
        $rc -> get('/networks/{symbol}', [$this, 'getNetwork']);
    }
    
    public function getAllNetworks($path, $query, $body, $auth) {
        $th = $this;
        
        if(isset($query['asset']))
            return $this -> amqp -> call(
                'wallet.wallet',
                'getAsset',
                [ 'symbol' => $query['asset'] ]
            ) -> then(function($asset) use($th, $query) {
                if(!$asset['enabled'])
                    throw new Error('FORBIDDEN', 'Asset '.$query['asset'].' is out of service', 403);
                
                $resp = $th -> networks -> getAnPairs([
                    'asset' => @$asset['assetid'],
                    'enabled' => true,
                    'enabledNetwork' => true,
                    'offset' => @$query['offset'],
                    'limit' => @$queryp['limit']
                ]);
                
                $networks = [];
                foreach($resp['an'] as $k => $v)
                    $networks[$k] = $this -> ptpNetwork($v['network']);
                
                return [
                    'networks' => $networks,
                    'more' => $resp['more']
                ];
            });
        
        $resp = $th -> networks -> getNetworks([
            'enabled' => true,
            'offset' => @$query['offset'],
            'limit' => @$queryp['limit']
        ]);
        
        foreach($resp['networks'] as $k => $v)
            $resp['networks'][$k] = $this -> ptpNetwork($v);
        
        return $resp;
    }
    
    public function getNetwork($path, $query, $body, $auth) {
        $network = $th -> networks -> getNetworks([
            'symbol' => $path['symbol']
        ]);
        
        if(!$network['enabled'])
            throw new Error('FORBIDDEN', 'Network '.$path['symbol'].' is out of service', 403);
        
        return $this -> ptpNetwork($network);
    }
    
    private function ptpNetwork($record) {
        return [
            'symbol' => $record['symbol'],
            'name' => $record['symbol'],
            'iconUrl' => $record['iconUrl']
        ];
    }
}

?>