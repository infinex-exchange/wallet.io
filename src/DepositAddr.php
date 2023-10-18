<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use Infinex\Database\Search;
use function Infinex\Validation\validateId;
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
            'getDepositAddresses',
            [$this, 'getDepositAddresses']
        );
        
        $promises[] = $this -> amqp -> method(
            'getDepositAddress',
            [$this, 'getDepositAddress']
        );
        
        $promises[] = $this -> amqp -> method(
            'getSetDepositAddress',
            [$this, 'getSetDepositAddress']
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
        
        $promises[] = $this -> amqp -> unreg('getDepositAddresses');
        $promises[] = $this -> amqp -> unreg('getDepositAddress');
        
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
    
    public function getDepositAddresses($body) {
        if(!is_array($body)) $body = [];
        
        if(isset($body['netid']) && !is_string($body['netid']))
            throw new Error('VALIDATION_ERROR', 'netid');
        if(isset($body['shardno']) && !validateId($body['shardno']))
            throw new Error('VALIDATION_ERROR', 'shardno');
        if(array_key_exists('uid', $body) && $body['uid'] !== null && !validateId($body['uid']))
            throw new Error('VALIDATION_ERROR', 'uid');
            
        $pag = new Pagination\Offset(50, 500, $body);
        $search = new Search(
            [
                'address',
                'memo'
            ],
            $body
        );
            
        $task = [];
        $search -> updateTask($task);
        
        $sql = 'SELECT addrid,
                       netid,
                       shardno,
                       address,
                       memo,
                       uid
                FROM deposit_addr
                WHERE 1=1';
        
        if(isset($body['netid'])) {
            $task[':netid'] = $body['netid'];
            $sql .= ' AND netid = :netid';
        }
        
        if(isset($body['shardno'])) {
            $task[':shardno'] = $body['shardno'];
            $sql .= ' AND shardno = :shardno';
        }
        
        if(array_key_exists('uid', $body)) {
            $task[':uid'] = $body['uid'];
            $sql .= ' AND uid IS NOT DISTINCT FROM :uid';
        }
            
        $sql .= $search -> sql()
             .' ORDER BY addrid ASC'
             . $pag -> sql();
            
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
            
        $addresses = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $addresses[] = $this -> rtrAddress($row);
        }
            
        return [
            'addresses' => $addresses,
            'more' => $pag -> more
        ];
    }
    
    public function getDepositAddress($body) {
        if(!isset($body['addrid']))
            throw new Error('MISSING_DATA', 'addrid');
        
        if(!validateId($body['addrid']))
            throw new Error('VALIDATION_ERROR', 'addrid');
        
        $task = [
            ':addrid' => $body['addrid']
        ];
        
        $sql = 'SELECT addrid,
                       netid,
                       shardno,
                       address,
                       memo,
                       uid
                FROM deposit_addr
                WHERE addrid = :addrid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Address '.$body['addrid'].' not found');
            
        return $this -> rtrAddress($row);
    }
    
    public function getSetDepositAddress($body) {
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid');
        if(!isset($body['netid']))
            throw new Error('MISSING_DATA', 'netid');
        
        if(!validateId($body['uid']))
            throw new Error('VALIDATION_ERROR', 'uid');
        if(!is_string($body['netid']))
            throw new Error('VALIDATION_ERROR', 'netid');
        
        $this -> pdo -> beginTransaction();
        
        $task = [
            ':uid' => $body['uid'],
            ':netid' => $body['netid']
        ];
        
        $sql = 'SELECT addrid,
                       netid,
                       shardno,
                       address,
                       memo,
                       uid
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
                    RETURNING addrid,
                              netid,
                              shardno,
                              address,
                              memo,
                              uid';
            
            $q = $this -> pdo -> prepare($sql);
            $q -> execute($task);
            $row = $q -> fetch();
            
            if(!$row) {
                $this -> pdo -> rollBack();
                throw new Error('ASSIGN_ADDR_FAILED', 'Unable to assign new deposit address. Please try again later.', 500);
            }
        }
        
        $this -> pdo -> commit();
                
        return $this -> rtrAddress($row);
    }
    
    private function rtrAddress($row) {
        return [
            'addrid' => $row['addrid'],
            'netid' => $row['netid'],
            'shardno' => $row['shardno'],
            'address' => $row['address'],
            'memo' => $row['memo'],
            'uid' => $row['uid']
        ];
    }
}

?>