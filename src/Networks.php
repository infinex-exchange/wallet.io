<?php

use Infinex\Exceptions\Error;
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
            'symbolToNetId',
            function($body) use($th) {
                return $th -> symbolToNetId(
                    $body['symbol'],
                    $body['allowDisabled']
                );
            }
        );
        
        $promises[] = $this -> amqp -> method(
            'netIdToSymbol',
            function($body) use($th) {
                return $th -> netIdToSymbol($body['netid']);
            }
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
        
        $promises[] = $this -> amqp -> unreg('symbolToNetId');
        $promises[] = $this -> amqp -> unreg('netIdToSymbol');
        
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
    
    public function symbolToNetId($symbol, $allowDisabled) {
        // Nonsense function, but very important for future changes
        
        if(!$this -> validateNetworkSymbol($symbol))
            throw new Error('VALIDATION_ERROR', 'Invalid network symbol', 400);
            
        $task = array(
            ':symbol' => $symbol
        );
        
        $sql = 'SELECT netid,
                       enabled
                FROM networks
                WHERE netid = :symbol';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
            
        if(!$row)
            throw new Error('NOT_FOUND', 'Network '.$symbol.' not found', 404);
        
        if(!$allowDisabled && !$row['enabled'])
            throw new Error('FORBIDDEN', 'Network '.$symbol.' is disabled', 403);
        
        return $row['netid'];
    }
    
    public function netIdToSymbol($netid) {
        return $netid;
    }
    
    private function validateNetworkSymbol($symbol) {
        return preg_match('/^[A-Z0-9_]{1,32}$/', $symbol);
    }
}

?>