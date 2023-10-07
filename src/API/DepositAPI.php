<?php

use Infinex\Exceptions\Error;

class DepositAPI {
    private $log;
    private $pdo;
    private $depositAddr;
    private $an;
    
    function __construct($log, $pdo, $depositAddr, $an) {
        $this -> log = $log;
        $this -> pdo = $pdo;
        $this -> depositAddr = $depositAddr;
        $this -> an = $an;
        
        $this -> log -> debug('Initialized deposit API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/deposit/{asset}/{network}', [$this, 'deposit']);
    }
    
    public function deposit($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        return $this -> an -> resolveAssetNetworkPair(
            $path['asset'],
            $path['network'],
            false
        ) -> then(function($pairing) use($th, $auth) {
            // Get network details
        
            $task = [
                ':netid' => $pairing['netid']
            ];
            
            $sql = 'SELECT confirms_target,
                           memo_name,
                           native_qr_format,
                           token_qr_format,
                           deposit_warning,
                           block_deposits_msg
                    FROM networks
                    WHERE netid = :netid';
            
            $q = $th -> pdo -> prepare($sql);
            $q -> execute($task);
            $infoNet = $q -> fetch();
            
            if($infoNet['block_deposits_msg'] !== null)
                throw new Error('FORBIDDEN', $infoNet['block_deposits_msg'], 403);
        
            // Get asset_network details
            
            $task = [
                ':assetid' => $pairing['assetid'],
                ':netid' => $pairing['netid']
            ];
            
            $sql = 'SELECT contract,
                           deposit_warning,
                           block_deposits_msg
                    FROM asset_network
                    WHERE assetid = :assetid
                    AND netid = :netid';
            
            $q = $th -> pdo -> prepare($sql);
            $q -> execute($task);
            $infoAn = $q -> fetch();
            
            if($infoAn['block_deposits_msg'] !== null)
                throw new Error('FORBIDDEN', $infoAn['block_deposits_msg'], 403);
        
            // Get minimal amount
            
            $minAmount = $th -> an -> getMinDepositAmount($pairing['assetid'], $pairing['netid']);
            
            // Get deposit address
            
            $infoAddr = $th -> depositAddr -> getDepositAddr($auth['uid'], $pairing['netid']);
            
            // Get shard details
        
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
            
            $operating = time() - intval($infoShard['last_ping']) <= 5 * 60;
        
            // Prepare response
                
            $resp = [
                'confirmTarget' => $infoNet['confirms_target'],
                'memoName' => null,
                'memo' => null,
                'qrCode' => null,
                'warnings' => [],
                'operating' => $operating,
                'contract' => $infoAn['contract'],
                'address' => $infoAddr['address'],
                'minAmount' => $minAmount
            ];
            
            // Memo only if both set
            if($infoNet['memo_name'] !== null && $infoAddr['memo'] !== null) {
                $resp['memoName'] = $infoNet['memo_name'];
                $resp['memo'] = $infoAddr['memo'];
            }
            
            // Qr code for native
            if($infoAn['contract'] === NULL && $infoNet['native_qr_format'] !== NULL) {
                $qrContent = $infoNet['native_qr_format'];
                $qrContent = str_replace('{{ADDRESS}}', $infoAddr['address'], $qrContent);
                $qrContent = str_replace('{{MEMO}}', $infoAddr['memo'], $qrContent);
                $resp['qrCode'] = $qrContent;
            }
            
            // Qr code for token
            else if($infoAn['contract'] !== NULL && $infoNet['token_qr_format'] !== NULL) {
                $qrContent = $infoNet['token_qr_format'];
                $qrContent = str_replace('{{ADDRESS}}', $infoAddr['address'], $qrContent);
                $qrContent = str_replace('{{MEMO}}', $infoAddr['memo'], $qrContent);
                $qrContent = str_replace('{{CONTRACT}}', $infoAn['contract'], $qrContent);
                $resp['qrCode'] = $qrContent;
            }
            
            // Warnings
            if($infoNet['deposit_warning'] !== null)
                $resp['warnings'][] = $infoNet['deposit_warning'];
            if($infoAn['deposit_warning'] !== null)
                $resp['warnings'][] = $infoAn['deposit_warning'];
            if($infoShard['deposit_warning'] !== null)
                $resp['warnings'][] = $infoShard['deposit_warning'];
            
            return $resp;
        });
    }
}

?>