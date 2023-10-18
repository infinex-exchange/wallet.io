<?php

use Infinex\Exceptions\Error;

class DepositAPI {
    private $log;
    private $pdo;
    private $depositAddr;
    private $networks;
    
    function __construct($log, $pdo, $depositAddr, $networks) {
        $this -> log = $log;
        $this -> pdo = $pdo;
        $this -> depositAddr = $depositAddr;
        $this -> networks = $networks;
        
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
            
            // Shard checks
            
            // Get minimal amount
            
            $minAmount = $th -> an -> getMinDepositAmount($pairing['assetid'], $pairing['netid']);
            
            /* Get shard details
        
            $task = [
                ':netid' => $pairing['netid'],
                ':shardno' => $infoAddr['shardno']
            ];
            
            $sql = 'SELECT wallet_shards.deposit_warning,
                           wallet_shards.block_deposits_msg,
                           EXTRACT(epoch FROM MAX(wallet_nodes.last_ping)) AS last_ping
                    FROM wallet_shards,
                         wallet_nodes
                    WHERE wallet_nodes.netid = wallet_shards.netid
                    AND wallet_nodes.shardno = wallet_shards.shardno
                    AND wallet_shards.netid = :netid
                    AND wallet_shards.shardno = :shardno
                    GROUP BY wallet_shards.netid,
                             wallet_shards.shardno,
                             wallet_shards.deposit_warning,
                             wallet_shards.block_deposits_msg';
            
            $q = $th -> pdo -> prepare($sql);
            $q -> execute($task);
            $infoShard = $q -> fetch();
            
            if($infoShard['block_deposits_msg'] !== null)
                throw new Error('FORBIDDEN', $infoShard['block_deposits_msg'], 403);
            
            $operating = time() - intval($infoShard['last_ping']) <= 5 * 60;*/
        
            // Prepare response
            $resp = [
                'address' => $address['address'],
                'memoName' => $an['network']['memoName'],
                'memo' => $address['memo'],
                'confirmTarget' => $an['network']['confirmTarget'],
                'contract' => $an['contract'],
                'minAmount' => $minAmount, ///////////////////////////
                'operating' => $operating, //////////////////////////
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
            /* TODO if($infoShard['deposit_warning'] !== null)
                $resp['warnings'][] = $infoShard['deposit_warning'];*/
            
            return $resp;
        });
    }
}

?>