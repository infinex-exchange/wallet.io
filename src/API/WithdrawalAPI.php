<?php

use Infinex\Exceptions\Error;
use function Infinex\Math\trimFloat;

class WithdrawalAPI {
    private $log;
    private $amqp;
    private $networks;
    private $withdrawals;
    
    function __construct($log, $amqp, $networks, $withdrawals) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> networks = $networks;
        $this -> withdrawals = $withdrawals;
        
        $this -> log -> debug('Initialized withdrawal API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/withdrawal/{network}/info/{asset}', [$this, 'preflight']);
        $rc -> get('/withdrawal/{network}/validation', [$this, 'validate']);
    }
    
    public function preflight($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'getAsset',
            [ 'symbol' => $path['asset'] ]
        ) -> then(function($asset) use($th, $path, $auth) {
            // Asset cheks
            if(!$asset['enabled'])
                throw new Error('FORBIDDEN', 'Asset '.$path['asset'].' is out of service', 403);
            
            // Get AN
            $an = $th -> networks -> getAnPair([
                'networkSymbol' => $path['network'],
                'assetid' => $asset['assetid']
            ]);
            
            // Network checks
            if(!$an['network']['enabled'])
                throw new Error('FORBIDDEN', 'Network '.$path['network'].' is out of service', 403);
            
            if($an['network']['blockWithdrawalsMsg'] !== null)
                throw new Error('FORBIDDEN', $network['blockWithdrawalsMsg'], 403);
            
            // AN checks
            if(!$an['enabled'])
                throw new Error('FORBIDDEN', 'Network '.$path['network'].' is out of service for '.$path['asset'], 403);
            
            if($an['blockWithdrawalsMsg'] !== null)
                throw new Error('FORBIDDEN', $an['blockWithdrawalsMsg'], 403);
        
            // Get minimal amount
            $minAmount = $th -> withdrawals -> resolveMinWithdrawalAmount($asset, $an);
        
            // Get fee min max
            $feeRange = $th -> withdrawals -> resolveFeeRange($an);
                    
            $resp = [
                'memoName' => $an['network']['memoName'],
                'contract' => $an['contract'],
                'minAmount' => trimFloat($minAmount -> toFixed($asset['defaultPrec'])),
                'feeMin' => trimFloat($feeRange['min'] -> toFixed($an['prec'])),
                'feeMax' => trimFloat($feeRange['max'] -> toFixed($an['prec'])),
                'feePrec' => $feeRange['prec'],
                'prec' => $an['prec'],
                'operating' => $an['network']['operating'],
                'warnings' => []
            ];
            
            // Warnings
            if($an['network']['withdrawalWarning'] !== null)
                $resp['warnings'][] = $an['network']['withdrawalWarning'];
            if($an['withdrawalWarning'] !== null)
                $resp['warnings'][] = $an['withdrawalWarning'];
            
            return $resp;
        });
    }
    
    public function validate($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $network = $this -> networks -> getNetwork([
            'symbol' => $path['network']
        ]);
            
        return $this -> withdrawals -> validateWithdrawalTarget([
            'netid' => $network['netid'],
            'address' => @$query['address'],
            'memo' => @$query['memo']
        ]);
    }
}

?>