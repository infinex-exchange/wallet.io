<?php

use Infinex\Exceptions\Error;
use React\Promise;
use Decimal\Decimal;
use function Infinex\Math\trimFloat;

class AssetNetwork {
    private $log;
    private $amqp;
    private $pdo;
    private $assets;
    private $networks;
    
    function __construct($log, $amqp, $pdo, $networks) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> networks = $networks;
        
        $this -> log -> debug('Initialized asset/network pairing manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'resolveAssetNetworkPair',
            function($body) use($th) {
                return $th -> resolveAssetNetworkPair(
                    $body['assetSymbol'],
                    $body['networkSymbol'],
                    $body['allowDisabled']
                );
            }
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started asset/network pairing manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start asset/network pairing manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('resolveAssetNetworkPair');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped asset/network pairing manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop asset/network pairing manager: '.((string) $e));
            }
        );
    }
    
    public function resolveAssetNetworkPair($assetSymbol, $networkSymbol, $allowDisabled) {
        $th = $this;
        
        $netid = $this -> networks -> symbolToNetId($networkSymbol, $allowDisabled);
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'symbolToAssetId',
            [
                'symbol' => $assetSymbol,
                'allowDisabled' => $allowDisabled
            ]
        ) -> then(
            function($resp) use($th, $netid, $assetSymbol, $networkSymbol, $allowDisabled) {
                $assetid = $resp['assetid'];
                
                $task = [
                    ':assetid' => $assetid,
                    ':netid' => $netid
                ];
                
                $sql = 'SELECT enabled
                        FROM asset_network
                        WHERE assetid = :assetid
                        AND netid = :netid';
                
                $q = $th -> pdo -> prepare($sql);
                $q -> execute($task);
                $row = $q -> fetch();
                
                if(!$row)
                    throw new Error('NOT_FOUND', "$networkSymbol is not a valid network for $assetSymbol", 404);
                
                if(!$allowDisabled && !$row['enabled'])
                    throw new Error('FORBIDDEN', "Network $networkSymbol is disabled for asset $assetSymbol", 403);
                
                return [
                    'assetid' => $assetid,
                    'netid' => $netid
                ];
            }
        );
    }
    
    public function getMinDepositAmount($assetid, $netid) {
        return $this -> commonGetMin('deposit', $assetid, $netid);
    }
    
    public function getMinWithdrawalAmount($assetid, $netid) {
        return $this -> commonGetMin('withdrawal', $assetid, $netid);
    }
    
    private function commonGetMin($type, $assetid, $netid) {
        $task = [
            ':assetid' => $assetid
        ];
        
        $sql = 'SELECT default_prec
                FROM assets
                WHERE assetid = :assetid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        $prec = $row['default_prec'];
        $min = new Decimal(1);
        $min = $min -> shift(-$prec);
        
        $col = 'min_'.$type;
        
        $sql = "SELECT $col
                FROM assets
                WHERE assetid = :assetid";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        $minAssets = new Decimal($row[$col]);
        if($minAssets > $min)
            $min = $minAssets;
        
        $task[':netid'] = $netid;
        
        $sql = "SELECT $col
                FROM asset_network
                WHERE assetid = :assetid
                AND netid = :netid";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        $minAn = new Decimal($row[$col]);
        if($minAn > $min)
            $min = $minAn;
        
        return trimFloat($min -> toFixed($prec));
    }
}

?>