<?php

use Infinex\Exceptions\Error;
use function Infinex\Validation\validateId;
use function Infinex\Validation\validateEmail;
use function Infinex\Validation\validateFloat;
use function Infinex\Math\trimFloat;
use React\Promise;
use Decimal\Decimal;

class Transfers {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized transfers manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> sub(
            'pendingInternalTransfer',
            [$this, 'pendingInternalTransfer']
        );
        
        $promises[] = $this -> amqp -> method(
            'createTransfer',
            [$this, 'createTransfer']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started transfers manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start transfers manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('createTransfer');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped transfers manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop transfers manager: '.((string) $e));
            }
        );
    }
    
    public function pendingInternalTransfer($body) {
        $th = $this;
        
        if(!isset($body['xid']) || !validateId($body['xid'])) {
            $this -> log -> error('Ignoring pending transfer with invalid xid');
            return;
        }
        
        if(!isset($body['amount']) || !validateFloat($body['amount'])) {
            $this -> log -> error('Ignoring pending transfer with invalid amount '.$body['xid']);
            return;
        }
        
        if(!isset($body['assetid']) || !is_string($body['assetid'])) {
            $this -> log -> error('Ignoring pending transfer with invalid assetid '.$body['xid']);
            return;
        }
        
        if(!isset($body['srcUid']) || !validateId($body['srcUid'])) {
            $this -> log -> error('Ignoring pending transfer with invalid srcUid '.$body['xid']);
            return;
        }
        
        if(!isset($body['srcEmail']) || !validateEmail($body['srcEmail'])) {
            $this -> log -> error('Ignoring pending transfer with invalid srcEmail '.$body['xid']);
            return;
        }
        
        if(!isset($body['dstUid']) || !validateId($body['dstUid'])) {
            $this -> log -> error('Ignoring pending transfer with invalid dstUid '.$body['xid']);
            return;
        }
        
        if(isset($body['memo']) && !$this -> validateTransferMessage($body['memo'])) {
            $this -> log -> error('Ignoring pending transfer with invalid memo '.$body['xid']);
            return;
        }
        
        if(!isset($body['lockid']) || !validateId($body['lockid'])) {
            $this -> log -> error('Ignoring pending transfer with invalid lockid '.$body['xid']);
            return;
        }
        
        $this -> pdo -> beginTransaction();
        
        $task = [
            ':uid' => $body['dstUid'],
            ':type' => 'TRANSFER_IN',
            ':assetid' => $body['assetid'],
            ':amount' => $body['amount'],
            ':status' => 'DONE',
            ':address' => $body['srcEmail'],
            ':memo' => @$body['memo'],
            ':opposite_xid' => $body['xid']
        ];
        
        $sql = 'INSERT INTO wallet_transactions(
                    uid,
                    type,
                    assetid,
                    amount,
                    status,
                    address,
                    memo,
                    opposite_xid
                ) VALUES (
                    :uid,
                    :type,
                    :assetid,
                    :amount,
                    :status,
                    :address,
                    :memo,
                    :opposite_xid
                )
                RETURNING xid';
        
        $q = $th -> pdo -> prepare($sql);
        $q -> execute($task);
        $insertTransferIn = $q -> fetch();
        
        $task = [
            ':xid' => $body['xid'],
            ':opposite_xid' => $insertTransferIn['xid']
        ];
        
        $sql = "UPDATE wallet_transactions
                SET status = 'DONE',
                    opposite_xid = :opposite_xid
                WHERE xid = :xid
                AND status = 'PENDING'
                RETURNING 1";
        
        $q = $th -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row) {
            $this -> pdo -> rollBack();
            $this -> log -> error(
                'Data integrity error in pending internal transfer queue! '.
                'Transaction '.$body['xid'].' not found or has unexpected status'
            );
            return;
        }
        
        $this -> pdo -> commit();
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'credit',
            [
                'uid' => $body['dstUid'],
                'assetid' => $body['assetid'],
                'amount' => $body['amount'],
                'reason' => 'TRANSFER_RECEIVED',
                'context' => $insertTransferIn['xid']
            ]
        ) -> then(
            function() use($th, $body) {
                return $th -> amqp -> call(
                    'wallet.wallet',
                    'commit',
                    [
                        'lockid' => $body['lockid'],
                        'reason' => 'TRANSFER_DONE',
                        'context' => $body['xid']
                    ]
                ) -> catch(function(Error $e) {
                    $th -> log -> error(
                        'Data integrity error in pending internal transfer queue! '.
                        'Cannot commit lock '.$body['lockid'].' for transaction '.$body['xid'].': '.
                        ((string) $e)
                    );
                });
            },
            function(Error $e) use($th, $body) {
                $th -> log -> error(
                    'Data integrity error in pending internal transfer queue! '.
                    'Created opposite transaction and updated transaction '.$body['xid'].
                    ' but call to wallet.wallet -> credit() failed: '.((string) $e)
                );
            }
        );
    }
    
    public function createTransfer($body) {
        $th = $this;
        
        if(!isset($body['amount']))
            throw new Error('MISSNIG_DATA', 'amount', 400);
            
        if(!validateFloat($body['amount']))
            throw new Error('VALIDATION_ERROR', 'amount', 400);
        
        if(isset($body['memo']) && !$this -> validateTransferMessage($body['memo']))
            throw new Error('VALIDATION_ERROR', 'memo', 400);
        if(isset($body['ignorePrec']) && !is_bool($body['ignorePrec']))
            throw new Error('VALIDATION_ERROR', 'ignorePrec');
        
        return Promise\all([
            $this -> amqp -> call(
                'account.account',
                'getUser',
                [
                    'uid' => @$body['srcUid'],
                    'email' => @$body['srcEmail']
                ]
            ),
            $this -> amqp -> call(
                'account.account',
                'getUser',
                [
                    'uid' => @$body['dstUid']
                    'email' => @$body['dstEmail']
                ]
            ),
            $this -> amqp -> call(
                'wallet.wallet',
                'getAsset',
                [
                    'assetid' => @$body['assetid'],
                    'symbol' => @$body['symbol']
                ]
            )
        ]) -> then(function($resolves) use($th, $body) {
            $srcUser = $resolves[0];
            $dstUser = $resolves[1];
            $asset = $resolves[2];
            
            if(!$asset['enabled'])
                throw new Error('FORBIDDEN', 'Transfer asset is out of service', 403);
            
            $dAmount = new Decimal($body['amount']);
            if(! @$body['ignorePrec'])
                $dAmount = $dAmount -> round($asset['defaultPrec'], Decimal::ROUND_TRUNCATE);
            if($dAmount <= 0)
                throw new Error('AMOUNT_OUT_OF_RANGE', 'Transfer amount is less than minimal amount', 416);
            
            $strAmount = trimFloat($dAmount -> toFixed($asset['defaultPrec']));
            
            return $this -> amqp -> call(
                'wallet.wallet',
                'lock',
                [
                    'uid' => $srcUser['uid'],
                    'assetid' => $asset['assetid'],
                    'amount' => $strAmount,
                    'reason' => 'TRANSFER_CREATE'
                ]
            ) -> then(function($lock) use($th, $body, $srcUser, $dstUser, $asset, $strAmount) {
                $task = [
                    ':uid' => $body['uid'],
                    ':type' => 'TRANSFER_OUT',
                    ':assetid' => $asset['assetid'],
                    ':amount' => $strAmount,
                    ':status' => 'PENDING',
                    ':address' => $body['address'],
                    ':memo' => @$body['memo'],
                    ':wd_fee_this' => '0',
                    ':lockid' => $lock['lockid']
                ];
                
                $sql = 'INSERT INTO wallet_transactions(
                            uid,
                            type,
                            assetid,
                            amount,
                            status,
                            address,
                            memo,
                            wd_fee_this,
                            lockid
                        ) VALUES (
                            :uid,
                            :type,
                            :assetid,
                            :amount,
                            :status,
                            :address,
                            :memo,
                            :wd_fee_this,
                            :lockid
                        )
                        RETURNING xid';
                
                $q = $th -> pdo -> prepare($sql);
                $q -> execute($task);
                $row = $q -> fetch();
                
                $this -> amqp -> pub(
                    'pendingInternalTransfer',
                    [
                        'xid' => $row['xid'],
                        'amount' => $strAmount,
                        'assetid' => $asset['assetid']
                        'srcUid' => $srcUser['uid'],
                        'srcEmail' => $srcUser['email'],
                        'dstUid' => $dstUser['uid'],
                        'memo' => @$body['memo'],
                        'lockid' => $lock['lockid']
                    ]
                );
                
                return [
                    'xid' => $row['xid']
                ];
            });
        });
    }
    
    private function validateTransferMessage($msg) {
        return preg_match('/^[a-zA-Z0-9 _,@#%\.\\\\\/\+\?\[\]\$\(\)\=\!\:\-]{1,255}$/', $msg);
    }
}

?>