<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use React\Promise;

class AssetNetwork {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized asset/network pairing manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getAnPairs',
            [$this, 'getAnPairs']
        );
        
        $promises[] = $this -> amqp -> method(
            'getAnPair',
            [$this, 'getAnPair']
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
        
        $promises[] = $this -> amqp -> unreg('getAnPairs');
        $promises[] = $this -> amqp -> unreg('getAnPair');
        
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
    
    public function getAnPairs($body) {
        if(isset($body['assetid']) && !is_string($body['assetid']))
            throw new Error('VALIDATION_ERROR', 'assetid');
        if(isset($body['netid']) && !is_string($body['netid']))
            throw new Error('VALIDATION_ERROR', 'netid');
        if(isset($body['enabled']) && !is_bool($body['enabled']))
            throw new Error('VALIDATION_ERROR', 'enabled');
        
        $pag = new Pagination\Offset(50, 500, $body);
    
        $task = [];
        
        $sql = 'SELECT assetid,
                       netid,
                       prec,
                       wd_fee_base,
                       enabled,
                       contract,
                       deposit_warning,
                       withdrawal_warning,
                       block_deposits_msg,
                       block_withdrawals_msg,
                       min_deposit,
                       min_withdrawal,
                       wd_fee_min,
                       wd_fee_max
                FROM asset_network
                WHERE 1=1';
        
        if(isset($body['assetid'])) {
            $task[':assetid'] = $body['assetid'];
            $sql .= ' AND assetid = :assetid';
        }
        
        if(isset($body['netid'])) {
            $task[':netid'] = $body['netid'];
            $sql .= ' AND netid = :netid';
        }
        
        if(isset($body['enabled'])) {
            $task[':enabled'] = $body['enabled'] ? 1 : 0;
            $sql .= ' AND enabled = :enabled';
        }
        
        $sql .= ' ORDER BY assetid ASC,
                           netid ASC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $an = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $an[] = $this -> rtrAnPair($row);
        }
        
        return [
            'an' => $an,
            'more' => $pag -> more
        ];
    }
    /// done here
    public function getAnPair($netid) {
        if(isset($body['netid']) && isset($body['symbol']))
            throw new Error('ARGUMENTS_CONFLICT', 'Both netid and symbol are set');
        else if(isset($body['netid'])) {
            if(!$this -> validateNetworkSymbol($body['netid']))
                throw new Error('VALIDATION_ERROR', 'netid');
            $dispNet = $body['netid'];
        }
        else if(isset($body['symbol'])) {
            if(!$this -> validateNetworkSymbol($body['symbol']))
                throw new Error('VALIDATION_ERROR', 'symbol', 400);
            $dispNet = $body['symbol'];
        }
        
        $task = [];
        
        $sql = 'SELECT netid,
                       description,
                       icon_url,
                       native_assetid,
                       confirms_target,
                       enabled,
                       memo_name,
                       native_qr_format,
                       token_qr_format,
                       deposit_warning,
                       withdrawal_warning,
                       block_deposits_msg,
                       block_withdrawals_msg
                FROM networks';
        
        if(isset($body['netid'])) {
            $task[':netid'] = $body['netid'];
            $sql .= ' WHERE netid = :netid';
            $dispNet = $body['netid'];
        }
        else {
            $task[':symbol'] = $body['symbol'];
            $sql .= ' WHERE symbol = :symbol';
            $dispNet = $body['symbol'];
        }
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Network '.$dispNet.' not found', 404);
        
        return $this -> rtrNetwork($row);
    }
    
    private function ptpANPair($row) {
        return [
            'netid' => $row['netid'],
            'symbol' => $row['netid'],
            'name' => $row['description'],
            'iconUrl' => $row['icon_url'],
            'nativeAssetid' => $row['native_assetid'],
            'confirmTarget' => $row['confirms_target'],
            'enabled' => $row['enabled'],
            'memoName' => $row['memo_name'],
            'qrFormatNative' => $row['native_qr_format'],
            'qrFormatToken' => $row['token_qr_format'],
            'depositWarning' => $row['deposit_warning'],
            'withdrawalWarning' => $row['withdrawal_warning'],
            'blockDepositsMsg' => $row['block_deposits_msg'],
            'blockWithdrawalsMsg' => $row['block_withdrawals_msg']
        ];
    }
}

?>