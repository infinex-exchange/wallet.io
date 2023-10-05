<?php

use Infinex\Exceptions\Error;
use React\Promise;

class DepositAddr {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized deposit address manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getDepositContext',
            function($body) use($th) {
                return $th -> getDepositContext($body);
            }
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started deposit address manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start deposit address manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getDepositContext');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped deposit address manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop deposit address manager: '.((string) $e));
            }
        );
    }
    
    public function getDepositContext($body) {
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid');
        if(!isset($body['netid']))
            throw new Error('MISSING_DATA', 'netid');
        
        // Get deposit address
        
        $this -> pdo -> beginTransaction();
        
        $task = [
            ':netid' => $body['netid'],
            ':uid' => $body['uid']
        ];
        
        $sql = 'SELECT shardno,
                       address,
                       memo
                FROM deposit_addr
                WHERE uid = :uid
                AND netid = :netid
                FOR UPDATE';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $infoAddr = $q -> fetch();
        
        if(!$infoAddr) {  
            $sql = 'UPDATE deposit_addr
                    SET uid = :uid
                    WHERE addrid IN (
                        SELECT addrid
                        FROM deposit_addr
                        WHERE netid = :netid
                        AND uid IS NULL
                        LIMIT 1
                    )
                    RETURNING shardno,
                              address,
                              memo';
            
            $q = $this -> pdo -> prepare($sql);
            $q -> execute($task);
            $infoAddr = $q -> fetch();
            
            if(!$infoAddr) {
                $this -> pdo -> rollBack();
                throw new Error('ASSIGN_ADDR_FAILED', 'Unable to assign new deposit address. Please try again later.', 500);
            }
        }
        
        $this -> pdo -> commit();
        
        // Get shard details
        
        $task = [
            ':netid' => $body['netid'],
            ':shardno' => $infoAddr['shardno']
        ];
        
        $sql = 'SELECT wallet_shards.deposit_warning,
                       wallet_shards.block_deposits_msg,
                       EXTRACT(epoch FROM MAX(wallet_nodes.last_ping)) AS last_ping
                FROM wallet_shards,
                     wallet_nodes
                WHERE wallet_nodes.netid = wallet_shards.netid
                AND wallet_nodes.shardno = wallet_shards.shardno
                AND wallet_shards.netid = :netid
                AND wallet_shards.shardno = :shardno
                GROUP BY wallet_shards.netid,
                         wallet_shards.shardno,
                         wallet_shards.deposit_warning,
                         wallet_shards.block_deposits_msg';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $infoShard = $q -> fetch();
        
        if($infoShard['block_deposits_msg'] !== null)
            throw new Error('FORBIDDEN', $infoShard['block_deposits_msg'], 403);
        
        $operating = time() - intval($infoShard['last_ping']) <= 5 * 60;
        
        // Prepare response
                
        $resp = [
            'memo' => $infoAddr['memo'],
            'warnings' => [],
            'operating' => $operating,
            'address' => $infoAddr['address']
        ];
        
        // Warnings
        if($infoShard['deposit_warning'] !== null)
            $resp['warnings'][] = $infoShard['deposit_warning'];
        
        return $resp;
    }
}

?>