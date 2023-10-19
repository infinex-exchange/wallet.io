<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use function Infinex\Validation\validateId;
use React\Promise;

class Nodes {
    private $log;
    private $amqp;
    private $pdo;
    private $operatingTimeout;
    
    function __construct($log, $amqp, $pdo, $operatingTimeout) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> operatingTimeout = $operatingTimeout;
        
        $this -> log -> debug('Initialized wallet nodes manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getNodes',
            [$this, 'getNodes']
        );
        
        $promises[] = $this -> amqp -> method(
            'getNode',
            [$this, 'getNode']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started wallet nodes manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start wallet nodes manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getNodes');
        $promises[] = $this -> amqp -> unreg('getNode');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped wallet nodes manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop wallet nodes manager: '.((string) $e));
            }
        );
    }
    
    public function getNodes($body) {
        if(isset($body['netid']) && !is_string($body['netid']))
            throw new Error('VALIDATION_ERROR', 'netid');
        
        if(isset($body['shardno'])) {
            if(!isset($body['netid']))
                throw new Error('ARGUMENTS_CONFLICT', 'shardno is allowed only with netid');
            
            if(!validateId($body['shardno']))
                throw new Error('VALIDATION_ERROR', 'shardno');
        }
            
        $pag = new Pagination\Offset(50, 500, $body);
            
        $task = [];
        
        $sql = 'SELECT nodeid,
                       netid,
                       shardno,
                       EXTRACT(epoch FROM last_ping) AS last_ping
                FROM wallet_nodes
                WHERE 1=1';
        
        if(isset($body['netid'])) {
            $task[':netid'] = $body['netid'];
            $sql .= ' AND netid = :netid';
        }
        
        if(isset($body['shardno'])) {
            $task[':shardno'] = $body['shardno'];
            $sql .= ' AND shardno = :shardno';
        }
            
        $sql .= ' ORDER BY netid ASC,
                           shardno ASC,
                           nodeid ASC'
             . $pag -> sql();
            
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
            
        $nodes = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $nodes[] = $this -> rtrNode($row);
        }
            
        return [
            'nodes' => $nodes,
            'more' => $pag -> more
        ];
    }
    
    public function getNode($body) {
        if(!isset($body['nodeid']))
            throw new Error('MISSING_DATA', 'nodeid');
        
        if(!validateId($body['nodeid']))
            throw new Error('VALIDATION_ERROR', 'nodeid');
        
        $task = [
            ':nodeid' => $body['nodeid']
        ];
        
        $sql = 'SELECT nodeid,
                       netid,
                       shardno,
                       EXTRACT(epoch FROM last_ping) AS last_ping
                FROM wallet_nodes
                WHERE nodeid = :nodeid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Node '.$body['nodeid'].' not found');
            
        return $this -> rtrNode($row);
    }
    
    public function getOperatingStatus($netid, $shardno = null) {
        $task = [
            ':netid' => $netid
        ];
        
        $sql .= 'SELECT EXTRACT(epoch FROM MAX(last_ping)) AS last_ping
                 FROM wallet_nodes
                 WHERE netid = :netid';
        
        if($shardno) {
            $task[':shardno'] = $shardno;
            $sql .= ' AND shardno = :shardno';
        }
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        return [
            'lastPing' => $row['last_ping'],
            'operating' => time() - intval($row['last_ping']) <= $this -> operatingTimeout
        ];
    }
    
    private function rtrNode($row) {
        return [
            'nodeid' => $row['nodeid'],
            'netid' => $row['netid'],
            'shardno' => $row['shardno'],
            'lastPing' => $row['last_ping'],
            'operating' => time() - intval($row['last_ping']) <= $this -> operatingTimeout
        ];
    }
}

?>