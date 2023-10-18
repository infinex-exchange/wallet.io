<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use React\Promise;

class Networks {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized networks manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getNetworks',
            [$this, 'getNetworks']
        );
        
        $promises[] = $this -> amqp -> method(
            'getNetwork',
            [$this, 'getNetwork']
        );
        
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
                $th -> log -> info('Started networks manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start networks manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getNetworks');
        $promises[] = $this -> amqp -> unreg('getNetwork');
        $promises[] = $this -> amqp -> unreg('getAnPairs');
        $promises[] = $this -> amqp -> unreg('getAnPair');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped networks manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop networks manager: '.((string) $e));
            }
        );
    }
    
    public function getNetworks($body) {
        if(isset($body['enabled']) && !is_bool($body['enabled']))
            throw new Error('VALIDATION_ERROR', 'enabled');
        
        $pag = new Pagination\Offset(50, 500, $body);
    
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
                FROM networks
                WHERE 1=1';
        
        if(isset($body['enabled'])) {
            $task[':enabled'] = $body['enabled'] ? 1 : 0;
            $sql .= ' AND enabled = :enabled';
        }
        
        $sql .= ' ORDER BY netid ASC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $networks = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $networks[] = $this -> rtrNetwork($row);
        }
        
        return [
            'networks' => $networks,
            'more' => $pag -> more
        ];
    }
    
    public function getNetwork($netid) {
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
    
    public function getAnPairs($body) {
        if(isset($body['assetid']) && !is_string($body['assetid']))
            throw new Error('VALIDATION_ERROR', 'assetid');
        if(isset($body['netid']) && !is_string($body['netid']))
            throw new Error('VALIDATION_ERROR', 'netid');
        if(isset($body['enabled']) && !is_bool($body['enabled']))
            throw new Error('VALIDATION_ERROR', 'enabled');
        if(isset($body['enabledNetwork']) && !is_bool($body['enabledNetwork']))
            throw new Error('VALIDATION_ERROR', 'enabledNetwork');
        
        if(isset($body['netid']))
            $network = $this -> getNetwork([ 'netid' => $body['netid'] ]);
        else
            $network = null;
        
        $pag = new Pagination\Offset(50, 500, $body);
    
        $task = [];
        
        $sql = 'SELECT asset_network.assetid,
                       asset_network.netid,
                       asset_network.prec,
                       asset_network.wd_fee_base,
                       asset_network.enabled,
                       asset_network.contract,
                       asset_network.deposit_warning,
                       asset_network.withdrawal_warning,
                       asset_network.block_deposits_msg,
                       asset_network.block_withdrawals_msg,
                       asset_network.min_deposit,
                       asset_network.min_withdrawal,
                       asset_network.wd_fee_min,
                       asset_network.wd_fee_max
                FROM asset_network,
                     networks
                WHERE asset_network.netid = networks.netid';
        
        if(isset($body['assetid'])) {
            $task[':assetid'] = $body['assetid'];
            $sql .= ' AND asset_network.assetid = :assetid';
        }
        
        if(isset($body['netid'])) {
            $task[':netid'] = $body['netid'];
            $sql .= ' AND asset_network.netid = :netid';
        }
        
        if(isset($body['enabled'])) {
            $task[':enabled'] = $body['enabled'] ? 1 : 0;
            $sql .= ' AND asset_network.enabled = :enabled';
        }
        
        if(isset($body['enabledNetwork'])) {
            $task[':enabled_network'] = $body['enabledNetwork'] ? 1 : 0;
            $sql .= ' AND networks.enabled = :enabled_network';
        }
        
        $sql .= ' ORDER BY assetid ASC,
                           netid ASC'
             . $pag -> sql();
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $an = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $an[] = $this -> rtrAnPair(
                $row,
                $network ? $network : $this -> getNetwork([ 'netid' => $row['netid'] ])
            );
        }
        
        return [
            'an' => $an,
            'more' => $pag -> more
        ];
    }
    
    public function getAnPair($netid) {
        if(!isset($body['assetid']))
            throw new Error('MISSING_DATA', 'assetid');
        if(!isset($body['netid']))
            throw new Error('MISSING_DATA', 'netid');
        
        if(!is_string($body['assetid']))
            throw new Error('VALIDATION_ERROR', 'assetid');
        if(!is_string($body['netid']))
            throw new Error('VALIDATION_ERROR', 'netid');
        
        $network : $this -> getNetwork([ 'netid' => $body['netid'] ]);
        
        $task = [
            ':assetid' => $body['assetid'],
            ':netid' => $body['netid']
        ];
        
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
                WHERE assetid = :assetid
                AND netid = :netid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error(
                'NOT_FOUND',
                'Network '.$body['netid'].' is not associated with asset '.$body['assetid'],
                404
            );
        
        return $this -> rtrAnPair($row, $network);
    }
    
    private function rtrNetwork($row) {
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
    
    private function rtrAnPair($row, $network) {
        return [
            'assetid' => $row['assetid'],
            'prec' => $row['prec']
            'wdFeeBase' => $row['wd_fee_base'],
            'enabled' => $row['enabled'],
            'contract' => $row['contract'],
            'depositWarning' => $row['deposit_warning'],
            'withdrawalWarning' => $row['withdrawal_warning'],
            'blockDepositsMsg' => $row['block_deposits_msg'],
            'blockWithdrawalsMsg' => $row['block_withdrawals_msg'],
            'minDeposit' => $row['min_deposit'],
            'minWithdrawal' => $row['min_withdrawal'],
            'wdFeeMin' => $row['wd_fee_min'],
            'wdFeeMax' => $row['wd_fee_max'],
            'network' => $network
        ];
    }
    
    private function validateNetworkSymbol($symbol) {
        return preg_match('/^[A-Z0-9_]{1,32}$/', $symbol);
    }
}

?>