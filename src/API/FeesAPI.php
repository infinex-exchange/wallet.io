<?php

use Infinex\Exceptions\Error;
use function Infinex\Math\trimFloat;

class FeesAPI {
    private $log;
    private $amqp;
    private $networks;
    private $deposits;
    private $withdrawals;
    
    function __construct($log, $amqp, $networks, $deposits, $withdrawals) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> networks = $networks;
        $this -> deposits = $deposits;
        $this -> withdrawals = $withdrawals;
        
        $this -> log -> debug('Initialized fees API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/fees', [$this, 'getAllFees']);
        $rc -> get('/fees/{asset}', [$this, 'getFees']);
    }
    
    public function getAllFees($path, $query, $body, $auth) {
        $th = $this;
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'getAssets',
            [
                'enabled' => true,
                'offset' => @$query['offset'],
                'limit' => @$query['limit']
            ]
        ) -> then(function($resp) use($th) {
            $fees = [];
            
            foreach($resp['assets'] as $asset)
                $fees[] = $th -> getFeesForAsset($asset);
            
            return [
                'fees' => $fees,
                'more' => $resp['more']
            ];
        });
    }
    
    public function getFees($path, $query, $body, $auth) {
        $th = $this;
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'getAsset',
            [ 'symbol' => $path['asset'] ]
        ) -> then(function($asset) use($th, $path) {
            if(!$asset['enabled'])
                throw new Error('FORBIDDEN', 'Asset '.$path['asset'].' is out of service', 403);
                
            return $th -> getFeesForAsset($asset);
        });
    }
    
    private function getFeesForAsset($asset) {
        $an = $this -> networks -> getAnPairs([
            'assetid' => $asset['assetid'],
            'enabled' => true,
            'enabledNetwork' => true
        ]);
        
        $networks = [];
        foreach($an['an'] as $an) {
            $wdMinAmount = $this -> withdrawals -> resolveMinWithdrawalAmount($asset, $an);
            $depoMinAmount = $this -> deposits -> resolveMinDepositAmount($asset, $an);
            $feeRange = $this -> withdrawals -> resolveFeeRange($an);
            
            $networks[] = [
                'symbol' => $an['network']['symbol'],
                'name' => $an['network']['name'],
                'iconUrl' => $an['network']['iconUrl'],
                'deposit' => [
                    'minAmount' => trimFloat($depoMinAmount -> toFixed($asset['defaultPrec']))
                ],
                'withdrawal' => [
                    'minAmount' => trimFloat($wdMinAmount -> toFixed($asset['defaultPrec'])),
                    'feeMin' => trimFloat($feeRange['min'] -> toFixed($an['prec'])),
                    'feeMax' => trimFloat($feeRange['max'] -> toFixed($an['prec']))
                ]
            ];
        }
        
        return [
            'symbol' => $asset['symbol'],
            'name' => $asset['name'],
            'iconUrl' => $asset['iconUrl'],
            'networks' => $networks
        ];
    }
}

?>