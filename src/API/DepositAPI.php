<?php

use Infinex\Exceptions\Error;

class DepositAPI {
    private $log;
    private $amqp;
    private $pdo;
    private $networks;
    private $shards;
    private $depositAddr;
    private $deposits;
    
    function __construct(
        $log,
        $amqp,
        $pdo,
        $networks,
        $shards,
        $depositAddr,
        $deposits
    ) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> networks = $networks;
        $this -> shards = $shards;
        $this -> depositAddr = $depositAddr;
        $this -> deposits = $deposits;
        
        $this -> log -> debug('Initialized deposit API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/deposit/{network}/{asset}', [$this, 'deposit']);
    }
    
    public function deposit($path, $query, $body, $auth) {
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
                'networkSymbol' => $path['symbol'],
                'assetid' => $asset['assetid']
            ]);
            
            // Network checks
            if(!$an['network']['enabled'])
                throw new Error('FORBIDDEN', 'Network '.$path['network'].' is out of service', 403);
            
            if($an['network']['blockDepositsMsg'] !== null)
                throw new Error('FORBIDDEN', $network['blockDepositsMsg'], 403);
            
            // AN checks
            if($an['enabled'])
                throw new Error('FORBIDDEN', 'Network '.$path['network'].' is out of service for '.$path['asset'], 403);
            
            if($an['blockDepositsMsg'] !== null)
                throw new Error('FORBIDDEN', $an['blockDepositsMsg'], 403);
            
            // Get deposit address
            $address = $th -> depositAddr -> getSetDepositAddress([
                'uid' => $auth['uid'],
                'netid' => $an['network']['netid']
            ]);
            
            // Get shard
            $shard = $th -> shards -> getShard([
                'netid' => $an['network']['netid'],
                'shardno' => $address['shardno']
            ]);
            
            // Get minimal amount
            $minAmount = $th -> deposits -> resolveMinDepositAmount($asset, $an);
        
            // Prepare response
            $resp = [
                'address' => $address['address'],
                'memoName' => $an['network']['memoName'],
                'memo' => $address['memo'],
                'confirmTarget' => $an['network']['confirmTarget'],
                'contract' => $an['contract'],
                'minAmount' => $minAmount,
                'operating' => $shard['operating'],
                'qrCode' => null,
                'warnings' => []
            ];
            
            // Qr code for native
            if(
                $asset['assetid'] == $an['network']['nativeAssetid'] &&
                $an['network']['qrFormatNative'] !== NULL
            ) {
                $qrContent = $network['qrFormatNative'];
                $qrContent = str_replace('{{ADDRESS}}', $address['address'], $qrContent);
                $qrContent = str_replace('{{MEMO}}', $address['memo'], $qrContent);
                $resp['qrCode'] = $qrContent;
            }
            
            // Qr code for token
            else if(
                $asset['assetid'] != $an['network']['nativeAssetid'] &&
                $an['network']['qrFormatToken'] !== NULL
            ) {
                $qrContent = $network['qrFormatToken'];
                $qrContent = str_replace('{{ADDRESS}}', $address['address'], $qrContent);
                $qrContent = str_replace('{{MEMO}}', $address['memo'], $qrContent);
                $qrContent = str_replace('{{CONTRACT}}', $an['contract'], $qrContent);
                $resp['qrCode'] = $qrContent;
            }
            
            // Warnings
            if($an['network']['depositWarning'] !== null)
                $resp['warnings'][] = $an['network']['depositWarning'];
            if($an['depositWarning'] !== null)
                $resp['warnings'][] = $an['depositWarning'];
            if($shard['depositWarning'] !== null)
                $resp['warnings'][] = $shard['depositWarning'];
            
            return $resp;
        });
    }
}

?>