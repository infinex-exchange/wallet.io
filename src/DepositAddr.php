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
            'getDepositAddr',
            function($body) use($th) {
                return $th -> getDepositAddr(
                    $body['uid'],
                    $body['netid']
                );
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
        
        $promises[] = $this -> amqp -> unreg('getDepositAddr');
        
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
    
    public function getDepositAddr($uid, $netid) {
        $this -> pdo -> beginTransaction();
        
        $task = [
            ':uid' => $uid,
            ':netid' => $netid
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
        $row = $q -> fetch();
        
        if(!$row) {  
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
            $row = $q -> fetch();
            
            if(!$row) {
                $this -> pdo -> rollBack();
                throw new Error('ASSIGN_ADDR_FAILED', 'Unable to assign new deposit address. Please try again later.', 500);
            }
        }
        
        $this -> pdo -> commit();
                
        return [
            'address' => $row['address'],
            'memo' => $row['memo'],
            'shardno' => $row['shardno']
        ];
    }
}

?>