<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use function Infinex\Validation\validateId;
use React\Promise;

class Shards {
    private $log;
    private $amqp;
    private $pdo;
    private $nodes;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized wallet shards manager');
    }
    
    public function setNodes($nodes) {
        $this -> nodes = $nodes;
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getShards',
            [$this, 'getShards']
        );
        
        $promises[] = $this -> amqp -> method(
            'getShard',
            [$this, 'getShard']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started wallet shards manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start wallet shards manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getShards');
        $promises[] = $this -> amqp -> unreg('getShard');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped wallet shards manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop wallet shards manager: '.((string) $e));
            }
        );
    }
    
    public function getShards($body) {
        if(isset($body['netid']) && !is_string($body['netid']))
            throw new Error('VALIDATION_ERROR', 'netid');
            
        $pag = new Pagination\Offset(50, 500, $body);
            
        $task = [];
        
        $sql = 'SELECT netid,
                       shardno,
                       deposit_warning,
                       block_deposits_msg,
                       block_withdrawals_msg
                FROM wallet_shards
                WHERE 1=1';
        
        if(isset($body['netid'])) {
            $task[':netid'] = $body['netid'];
            $sql .= ' AND netid = :netid';
        }
            
        $sql .= ' ORDER BY netid ASC,
                           shardno ASC'
             . $pag -> sql();
            
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
            
        $shards = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $shards[] = $this -> rtrShard($row);
        }
            
        return [
            'shards' => $shards,
            'more' => $pag -> more
        ];
    }
    
    public function getShard($body) {
        if(!isset($body['netid']))
            throw new Error('MISSING_DATA', 'netid');
        if(!isset($body['shardno']))
            throw new Error('MISSING_DATA', 'shardno');
        
        if(!is_string($body['netid']))
            throw new Error('VALIDATION_ERROR', 'netid');
        if(!validateId($body['shardno']))
            throw new Error('VALIDATION_ERROR', 'shardno');
        
        $task = [
            ':netid' => $body['netid'],
            ':shardno' => $body['shardno']
        ];
        
        $sql = 'SELECT netid,
                       shardno,
                       deposit_warning,
                       block_deposits_msg,
                       block_withdrawals_msg
                FROM wallet_shards
                WHERE netid = :netid
                AND shardno = :shardno';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error(
                'NOT_FOUND',
                'Shard '.$body['netid'].':'.$body['shardno'].' not found'
            );
            
        return $this -> rtrShard($row);
    }
    
    private function rtrShard($row) {
        return array_merge(
            [
                'netid' => $row['netid'],
                'shardno' => $row['shardno'],
                'depositWarning' => $row['deposit_warning'],
                'blockDepositsMsg' => $row['block_deposits_msg'],
                'blockWithdrawalsMsg' => $row['block_withdrawals_msg']
            ],
            $this -> nodes -> getOperatingStatus($row['netid'], $row['shardno'])
        );
    }
}

?>