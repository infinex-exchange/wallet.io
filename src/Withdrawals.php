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
            'getWithdrawalContext',
            function($body) use($th) {
                return $th -> getWithdrawalContext($body);
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
        
        $promises[] = $this -> amqp -> unreg('getWithdrawalContext');
        
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
    
    public function getWithdrawalContext($body) {
        if(!isset($body['netid']))
            throw new Error('MISSING_DATA', 'netid');
        
        // Get shard details
        
        $task = [
            ':netid' => $body['netid']
        ];
        
        $sql = 'SELECT EXTRACT(epoch FROM MAX(last_ping)) AS last_ping
                FROM wallet_nodes
                WHERE netid = :netid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $infoNodes = $q -> fetch();
        
        $operating = time() - intval($infoNodes['last_ping']) <= 5 * 60;
        
        return [
            'operating' => $operating
        ];
    }
}

?>