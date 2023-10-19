<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use function Infinex\Validation\validateId;
use function Infinex\Math\trimFloat;
use React\Promise;

class Transactions {
    private $log;
    private $amqp;
    private $pdo;
    
    private $allowedTypes = [
        'DEPOSIT',
        'WITHDRAWAL',
        'TRANSFER_IN',
        'TRANSFER_OUT'
    ];
    private $allowedStatus = [
        'PENDING',
        'PROCESSING',
        'DONE',
        'CANCEL_PENDING',
        'CANCELED',
        'BLOCKED',
        'DROPPED'
    ];
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized transactions manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getTransactions',
            [$this, 'getTransactions']
        );
        
        $promises[] = $this -> amqp -> method(
            'getTransaction',
            [$this, 'getTransaction']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started transactions manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start transactions manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getTransactions');
        $promises[] = $this -> amqp -> unreg('getTransaction');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped transactions manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop transactions manager: '.((string) $e));
            }
        );
    }
    
    public function getTransactions($body) {
        if(isset($body['uid']) && !validateId($body['uid']))
            throw new Error('VALIDATION_ERROR', 'uid');
        if(isset($body['type'])) {
            if(!is_array($body['type']))
                $body['type'] = [ $body['type'] ];
            
            foreach($body['type'] as $type)
                if(!in_array($body['type'], $this -> allowedTypes))
                    throw new Error(
                        'VALIDATION_ERROR',
                        'Allowed types: '.implode(', '.$this -> allowedTypes),
                        400
                    );
        }
        if(isset($body['assetid']) && !is_string($body['assetid']))
            throw new Error('VALIDATION_ERROR', 'assetid');
        if(isset($body['netid']) && !is_string($body['netid']))
            throw new Error('VALIDATION_ERROR', 'netid');
        if(isset($body['status'])) {
            if(!is_array($body['status']))
                $body['status'] = [ $body['status'] ];
            
            foreach($body['status'] as $status)
                if(!in_array($body['status'], $this -> allowedStatus))
                    throw new Error(
                        'VALIDATION_ERROR',
                        'Allowed statuses: '.implode(', '.$this -> allowedStatus),
                        400
                    );
        }
        if(isset($body['address']) && !is_string($body['address']))
            throw new Error('VALIDATION_ERROR', 'address', 400);
        if(array_key_exists($body['memo']) && $body['memo'] !== null && !is_string($body['memo']))
            throw new Error('VALIDATION_ERROR', 'memo', 400);
            
        $pag = new Pagination\Offset(50, 500, $body);
            
        $task = [];
        
        $sql = 'SELECT xid,
                       uid,
                       type,
                       assetid,
                       netid,
                       amount,
                       status,
                       EXTRACT(epoch FROM create_time) AS create_time,
                       address,
                       memo,
                       EXTRACT(epoch FROM exec_time) AS exec_time,
                       confirms,
                       confirms_target,
                       txid,
                       height,
                       wd_fee_this,
                       wd_fee_native,
                       bridge_issued,
                       send_mail,
                       executor_lock,
                       wd_fee_base
                FROM wallet_transactions
                WHERE 1=1';
        
        if(isset($body['uid'])) {
            $task[':uid'] = $body['uid'];
            $sql .= ' AND uid = :uid';
        }
        
        if(!empty($body['type'])) {
            $sql .= ' AND type IN(';
            
            for($i = 0; $i < count($body['type']); $i++) {
                if($i > 0) $sql .= ',';
                $task[':type'.$i] = $body['type'][$i];
                $sql .= ':type'.$i;
            }
            
            $sql .= ')';
        }
        
        if(isset($body['assetid'])) {
            $task[':assetid'] = $body['assetid'];
            $sql .= ' AND assetid = :assetid';
        }
        
        if(isset($body['netid'])) {
            $task[':netid'] = $body['netid'];
            $sql .= ' AND netid = :netid';
        }
        
        if(!empty($body['status'])) {
            $sql .= ' AND status IN(';
            
            foreach($i = 0; $i < count($body['status']); $i++) {
                if($i > 0) $sql .= ',';
                $task[':status'.$i] = $body['status'][$i];
                $sql .= ':status'.$i;
            }
            
            $sql .= ')';
        }
        
        if(isset($body['address'])) {
            $task[':address'] = $body['address'];
            $sql .= ' AND address = :address';
        }
        
        if(array_key_exists($body['memo'])) {
            $task[':memo'] = $body['memo'];
            $sql .= ' AND memo IS NOT DISTINCT FROM :memo';
        }
            
        $sql .= ' ORDER BY xid DESC'
             . $pag -> sql();
            
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
            
        $transactions = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $transactions[] = $this -> rtrTransaction($row);
        }
            
        return [
            'transactions' => $transactions,
            'more' => $pag -> more
        ];
    }
    
    public function getTransaction($body) {
        if(isset($body['xid']) && isset($body['txid']))
            throw new Error('ARGUMENTS_CONFLICT', 'xid and txid cannot be used together');
        else if(isset($body['xid'])) {
            if(!validateId($body['xid']))
                throw new Error('VALIDATION_ERROR', 'xid');
            
            $dispTx = $body['xid'];
        }
        else if(isset($body['txid'])) {
            if(!isset($body['netid']))
                throw new Error('MISSING_DATA', 'netid is required if txid is set');
            
            if(!is_string($body['netid']))
                throw new Error('VALIDATION_ERROR', 'netid');
            if(!is_string($body['txid']))
                throw new Error('VALIDATION_ERROR', 'txid');
            
            $dispTx = $body['netid'].':'.$body['txid'];
        }
        else
            throw new Error('MISSING_DATA', 'xid or netid + txid');
        
        $task = [];
        
        $sql = 'SELECT xid,
                       uid,
                       type,
                       assetid,
                       netid,
                       amount,
                       status,
                       EXTRACT(epoch FROM create_time) AS create_time,
                       address,
                       memo,
                       EXTRACT(epoch FROM exec_time) AS exec_time,
                       confirms,
                       confirms_target,
                       txid,
                       height,
                       wd_fee_this,
                       wd_fee_native,
                       bridge_issued,
                       send_mail,
                       executor_lock,
                       wd_fee_base
                FROM wallet_transactions
                WHERE 1=1';
        
        if(isset($body['xid'])) {
            $task[':xid'] = $body['xid'];
            $sql .= ' AND xid = :xid';
        }
        else {
            $task[':netid'] = $body['netid'];
            $task[':txid'] = $body['txid'];
            $sql .= ' AND netid = :netid
                      AND txid = :txid';
        }
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Transaction '.$dispTx.' not found');
            
        return $this -> rtrTransaction($row);
    }
    
    private function rtrTransaction($row) {
        return [
            'xid' => $row['xid'],
            'uid' => $row['uid'],
            'type' => $row['type'],
            'assetid' => $row['assetid'],
            'netid' => $row['netid'],
            'amount' => trimFloat($row['amount']),
            'status' => $row['status'],
            'createTime' => intval($row['createTime']),
            'address' => $row['address'],
            'memo' => $row['memo'],
            'execTime' => $row['exec_time'] ? intval($row['exec_time']) : null,
            'confirmations' => $row['confirms'],
            'confirmTarget' => $row['confirms_target'],
            'txid' => $row['txid'],
            'height' => $row['height'],
            'wdFeeThis' => $row['wd_fee_this'],
            'wdFeeNative' => $row['wd_fee_native'],
            'bridgeIssued' => $row['bridge_issued'],
            'silent' => !$row['send_mail'],
            'executorLock' => $row['executor_lock'],
            'wdFeeBase' => $row['wd_fee_base']
        ];
    }
}

?>