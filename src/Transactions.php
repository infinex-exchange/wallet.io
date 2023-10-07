<?php

use Infinex\Exceptions\Error;
use React\Promise;

class Transactions {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized transactions manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'newOutgoingTransaction',
            function($body) use($th) {
                return $th -> newOutgoingTransaction($body);
            }
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started transactions manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start transactions manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('newOutgoingTransaction');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped transactions manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop transactions manager: '.((string) $e));
            }
        );
    }
    
    public function newOutgoingTransaction($body) {
        if(!isset($body['type']))
            throw new Error('MISSING_DATA', 'type', 400);
        
        if($body['type'] != 'WITHDRAWAL' && $body['type'] != 'TRANSFER_OUT')
            throw new Error('VALIDATION_ERROR', 'Allowed types: WITHDRAWAL, TRANSFER_OUT', 400);
        
        //
    }
}

?>