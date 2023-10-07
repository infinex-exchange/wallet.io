<?php

use Infinex\Exceptions\Error;
use function Infinex\Math\trimFloat;
use Decimal\Decimal;

class WithdrawalAPI {
    private $log;
    private $amqp;
    private $pdo;
    private $withdrawals;
    private $networks;
    private $an;
    
    function __construct($log, $amqp, $pdo, $withdrawals, $networks, $an) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> withdrawals;
        $this -> networks = $networks;
        $this -> an = $an;
        
        $this -> log -> debug('Initialized withdrawal API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/withdrawal/{network}/{asset}', [$this, 'preflight']);
        $rc -> post('/withdrawal/{network}', [$this, 'validate']);
        $rc -> post('/withdrawal/{network}', [$this, 'validate']);
    }
    
    public function preflight($path, $query, $body, $auth) {
        $th = $this;
        
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        return $this -> an -> resolveAssetNetworkPair(
            $path['asset'],
            $path['network'],
            false
        ) -> then(function($pairing) use($th) {
            // Get network details
        
            $task = [
                ':netid' => $pairing['netid']
            ];
            
            $sql = 'SELECT memo_name,
                           withdrawal_warning,
                           block_withdrawals_msg
                    FROM networks
                    WHERE netid = :netid';
            
            $q = $th -> pdo -> prepare($sql);
            $q -> execute($task);
            $infoNet = $q -> fetch();
            
            if($infoNet['block_withdrawals_msg'] !== null)
                throw new Error('FORBIDDEN', $infoNet['block_withdrawals_msg'], 403);
        
            // Get asset_network details
            
            $task = [
                ':assetid' => $pairing['assetid'],
                ':netid' => $pairing['netid']
            ];
            
            $sql = 'SELECT contract,
                           prec,
                           wd_fee_base,
                           wd_fee_min,
                           wd_fee_max,
                           withdrawal_warning,
                           block_withdrawals_msg
                    FROM asset_network
                    WHERE assetid = :assetid
                    AND netid = :netid';
            
            $q = $th -> pdo -> prepare($sql);
            $q -> execute($task);
            $infoAn = $q -> fetch();
            
            if($infoAn['block_withdrawals_msg'] !== null)
                throw new Error('FORBIDDEN', $infoAn['block_withdrawals_msg'], 403);
        
            // Get min amount
            
            $minAmount = $th -> an -> getMinWithdrawalAmount($pairing['assetid'], $pairing['netid']);
            
            // Get nodes details
        
            $task = [
                ':netid' => $pairing['netid']
            ];
            
            $sql = 'SELECT EXTRACT(epoch FROM MAX(last_ping)) AS last_ping
                    FROM wallet_nodes
                    WHERE netid = :netid';
            
            $q = $th -> pdo -> prepare($sql);
            $q -> execute($task);
            $infoNodes = $q -> fetch();
            
            $operating = time() - intval($infoNodes['last_ping']) <= 5 * 60;
        
            // Prepare response
        
            $dFeeBase = new Decimal($infoAn['wd_fee_base']);
            
            $dFeeMin = new Decimal($infoAn['wd_fee_min']);
            $dFeeMin += $dFeeBase;
            
            $dFeeMax = new Decimal($infoAn['wd_fee_max']);
            $dFeeMax += $dFeeBase;
                    
            $resp = [
                'memoName' => $infoNet['memo_name'],
                'warnings' => [],
                'operating' => $operating,
                'contract' => $infoAn['contract'],
                'minAmount' => $minAmount,
                'prec' => $infoAn['prec'],
                'feeMin' => trimFloat($dFeeMin -> toFixed($infoAn['prec'])),
                'feeMax' => trimFloat($dFeeMax -> toFixed($infoAn['prec']))
            ];
            
            // Warnings
            if($infoNet['withdrawal_warning'] !== null)
                $resp['warnings'][] = $infoNet['withdrawal_warning'];
            if($infoAn['withdrawal_warning'] !== null)
                $resp['warnings'][] = $infoAn['withdrawal_warning'];
            
            return $resp;
        });
    }
    
    public function validate($path, $query, $body, $auth) {
        if(!$auth)
            throw new Error('UNAUTHORIZED', 'Unauthorized', 401);
        
        $netid = $this -> networks -> symbolToNetId($path['network'], false);
            
        return $this -> withdrawals -> validateWithdrawalTarget(
            $netid,
            isset($body['address']) ? $body['address'] : null,
            isset($body['memo']) ? $body['memo'] : null
        );
    }
}

?>