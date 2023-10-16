<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use React\Promise;

class Networks {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized networks manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getNetworks',
            [$this, 'getNetworks']
        );
        
        $promises[] = $this -> amqp -> method(
            'getNetwork',
            [$this, 'getNetwork']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started networks manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start networks manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getNetworks');
        $promises[] = $this -> amqp -> unreg('getNetwork');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped networks manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop networks manager: '.((string) $e));
            }
        );
    }
    
    public function getNetworks($body) {
        if(isset($body['assetid']) && !is_string($body['assetid']))
            throw new Error('VALIDATION_ERROR', 'assetid');
        if(isset($body['enabled']) && !is_bool($body['enabled']))
            throw new Error('VALIDATION_ERROR', 'enabled');
        
        $pag = new Pagination\Offset(50, 500, $query);
    
        $task = [];
        
        $sql = 'SELECT networks.netid,
                       networks.description,
                       networks.icon_url,
                       networks.memo_name
                FROM networks';
        
        if(isset($body['assetid'])) {
            $task[':assetid'] = $body['assetid'];
            $sql .= ', asset_network
                     WHERE asset_network.netid = networks.netid
                     AND asset_network.assetid = :assetid';
            
            if(@$body['enabled'])
                $sql .= ' AND asset_network.enabled = TRUE';
        }
        else
            $sql .= ' WHERE 1=1';
        
        if(@$body['enabled'])
            $sql .= ' AND networks.enabled = TRUE';
        
        $sql .= ' ORDER BY networks.netid ASC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $networks = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $networks[] = $this -> rtrNetwork($row);
        }
        
        return [
            'networks' => $networks,
            'more' => $pag -> more
        ];
    }
    
    public function getNetwork($netid) {
        if(isset($body['netid']) && isset($body['symbol']))
            throw new Error('ARGUMENTS_CONFLICT', 'Both netid and symbol are set');
        else if(isset($body['netid'])) {
            if(!$this -> validateNetworkSymbol($body['netid']))
                throw new Error('VALIDATION_ERROR', 'netid');
            $dispNet = $body['netid'];
        }
        else if(isset($body['symbol'])) {
            if(!$this -> validateNetworkSymbol($body['symbol']))
                throw new Error('VALIDATION_ERROR', 'symbol', 400);
            $dispNet = $body['symbol'];
        }
        
        $task = [];
        
        $sql = 'SELECT netid,
                       description,
                       icon_url,
                       memo_name
                FROM networks
                WHERE netid = :netid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        return $this -> rowToNetworkItem($row);
    }
    
    public function rowToNetworkItem($row) {
        return [
            'symbol' => $row['netid'],
            'name' => $row['description'],
            'iconUrl' => $row['icon_url'],
            'memoName' => $row['memo_name']
        ];
    }
    
    private function validateNetworkSymbol($symbol) {
        return preg_match('/^[A-Z0-9_]{1,32}$/', $symbol);
    }
}

?>