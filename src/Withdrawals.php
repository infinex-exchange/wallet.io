<?php

use Infinex\Exceptions\Error;
use React\Promise;

class Withdrawals {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized withdrawals manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'validateWithdrawalTarget',
            function($body) use($th) {
                return $th -> validateWithdrawalTarget(
                    $body['netid'],
                    isset($body['address']) ? $body['address'] : null,
                    isset($body['memo']) ? $body['memo'] : null
                );
            }
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started withdrawals manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start withdrawals manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('validateWithdrawalTarget');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped withdrawals manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop withdrawals manager: '.((string) $e));
            }
        );
    }
    
    public function validateWithdrawalTarget($netid, $address, $memo) {
        if($address === null && $memo === null)
            throw new Error('MISSING_DATA', 'At least one is required: address or memo', 400);
        
        if($memo !== null) {
            $task = [
                ':netid' => $netid
            ];
            
            $sql = 'SELECT 1
                    FROM networks
                    WHERE netid = :netid
                    AND memo_name IS NOT NULL';
            
            $q = $this -> pdo -> prepare($sql);
            $q -> execute($task);
            $row = $q -> fetch();
            
            if(!$row)
                throw new Error('CONFLICT', 'Network does not support memo but provided', 409);
        }
        
        return [
            'validAddress' => true,
            'validMemo' => true,
            'internal' => false
        ];
    }
}

?>