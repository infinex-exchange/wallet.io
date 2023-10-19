<?php

use Infinex\Exceptions\Error;
use React\Promise;
use Decimal\Decimal;

class Withdrawals {
    private $log;
    private $amqp;
    private $pdo;
    private $networks;
    private $depositAddr;
    
    function __construct($log, $amqp, $pdo, $networks, $depositAddr) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> networks = $networks;
        $this -> depositAddr = $depositAddr;
        
        $this -> log -> debug('Initialized withdrawals manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'validateWithdrawalTarget',
            [$this, 'validateWithdrawalTarget']
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
    
    public function validateWithdrawalTarget($body) {
        if(!isset($body['netid']))
            throw new Error('MISSING_DATA', 'netid');
        if(!is_string($body['netid']))
            throw new Error('VALIDATION_ERROR', 'netid');
        
        if(!isset($body['address']) && !isset($body['memo']))
            throw new Error('MISSING_DATA', 'At least one is required: address or memo', 400);
        if(isset($body['address']) && !is_string($body['address']))
            throw new Error('VALIDATION_ERROR', 'address', 400);
        if(isset($body['memo']) && !is_string($body['memo']))
            throw new Error('VALIDATION_ERROR', 'memo', 400);
        
        $resp = [
            'validAddress' => null,
            'validMemo' => null,
            'internal' => null
        ];
        
        if(isset($body['memo'])) {
            $network = $this -> networks -> getNetwork([
                'netid' => $body['netid']
            ]);
            
            if($network['memoName'] === null)
                throw new Error('CONFLICT', 'Network does not support memo but memo provided', 409);
            
            $resp['validMemo'] = true; // TODO
        }
        
        if(isset($body['address'])) {
            try {
                $address = $this -> depositAddr -> getDepositAddress([
                    'netid' => $body['netid'],
                    'address' => $body['address']
                ]);
                
                $resp['internal'] = true;
            }
            catch(Error $e) {
                if($e -> getStrCode() != 'NOT_FOUND')
                    throw $e;
                
                $resp['internal'] = false;
            }
            
            $resp['validAddress'] = true; // TODO
        }
        
        return $resp;
    }
    
    public function resolveMinWithdrawalAmount($asset, $an) {
        $min = new Decimal(1);
        $min = $min -> shift(-$asset['defaultPrec']);
        
        $minAsset = new Decimal($asset['minWithdrawal']);
        if($minAsset > $min)
            $min = $minAsset;
        
        $minAn = new Decimal($an['minWithdrawal']);
        if($minAn > $min)
            $min = $minAn;
        
        return $min;
    }
    
    public function resolveFeeRange($an) {
        $dFeeBase = new Decimal($an['wdFeeBase']);
            
        $dFeeMin = new Decimal($an['wdFeeMin']);
        $dFeeMin += $dFeeBase;
        
        $dFeeMax = new Decimal($an['wdFeeMax']);
        $dFeeMax += $dFeeBase;
        
        return [
            'min' => $dFeeMin,
            'max' => $dFeeMax
        ];
    }
}

?>