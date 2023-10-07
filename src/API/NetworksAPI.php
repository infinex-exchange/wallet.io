<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use React\Promise;

class NetworksAPI {
    private $log;
    private $pdo;
    private $networks;
    private $an;
    
    function __construct($log, $amqp, $pdo, $networks, $an) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> networks = $networks;
        $this -> an = $an;
        
        $this -> log -> debug('Initialized networks API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/networks', [$this, 'getAllNetworks']);
        $rc -> get('/networks/{symbol}', [$this, 'getNetwork']);
    }
    
    public function getAllNetworks($path, $query, $body, $auth) {
        $th = $this;
        
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
        
        return $promise -> then(function($assetid) use($th, $query) {
            $pag = new Pagination\Offset(50, 500, $query);
        
            $task = [];
            if($assetid)
                $task[':assetid'] = $assetid;
            
            $sql = 'SELECT networks.netid,
                           networks.description,
                           networks.icon_url
                    FROM networks';
            
            if($assetid)
                $sql .= ', asset_network';
            
            $sql .= ' WHERE networks.enabled = TRUE';
            
            if($assetid)
                $sql .= ' AND asset_network.netid = networks.netid
                          AND asset_network.assetid = :assetid';
            
            $sql .= ' ORDER BY networks.netid ASC'
                 . $pag -> sql();
            
            $q = $th -> pdo -> prepare($sql);
            $q -> execute($task);
            
            $networks = [];
            
            while($row = $q -> fetch()) {
                if($pag -> iter()) break;
                $networks[] = $th -> rowToRespItem($row);
            }
            
            return [
                'networks' => $networks,
                'more' => $pag -> more
            ];
        });
    }
    
    public function getNetwork($path, $query, $body, $auth) {
        $netid = $th -> networks -> symbolToNetId($path['symbol'], false);
        
        $task = [
            ':netid' => $netid
        ];
        
        $sql = 'SELECT netid,
                       description,
                       icon_url
                FROM networks
                WHERE netid = :netid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        return $this -> rowToRespItem($row);
    }
    
    private function rowToRespItem($row) {
        return [
            'symbol' => $row['netid'],
            'name' => $row['description'],
            'iconUrl' => $row['icon_url']
        ];
    }
}

?>