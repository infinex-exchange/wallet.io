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
        
        $promises[] = $this -> amqp -> method(
            'createTransfer',
            [$this, 'createTransfer']
        );
        
        $promises[] = $this -> amqp -> sub(
            'executeTransfer',
            [$this, 'executeTransfer_saga0']
        );
        
        $promises[] = $this -> amqp -> sub(
            'executeTransfer_saga1',
            [$this, 'executeTransfer_saga1']
        );
        
        $promises[] = $this -> amqp -> sub(
            'executeTransfer_saga2',
            [$this, 'executeTransfer_saga2']
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
        $promises[] = $this -> amqp -> unsub('executeTransfer');
        $promises[] = $this -> amqp -> unsub('executeTransfer_saga1');
        $promises[] = $this -> amqp -> unsub('executeTransfer_saga2');
        
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
    
    public function executeTransfer_saga0($body) {
        $this -> pdo -> beginTransaction();
        
        $task = [
            ':uid' => $body['dstUser']['uid'],
            ':type' => 'TRANSFER_IN',
            ':assetid' => $body['asset']['assetid'],
            ':amount' => $body['amount'],
            ':status' => 'DONE',
            ':address' => $body['srcUser']['email'],
            ':memo' => @$body['memo'],
            ':opposite_xid' => $body['xid'],
            ':create_time' => $body['createTime']
        ];
        
        $sql = 'INSERT INTO wallet_transactions(
                    uid,
                    type,
                    assetid,
                    amount,
                    status,
                    address,
                    memo,
                    opposite_xid,
                    create_time,
                    exec_time
                ) VALUES (
                    :uid,
                    :type,
                    :assetid,
                    :amount,
                    :status,
                    :address,
                    :memo,
                    :opposite_xid,
                    TO_TIMESTAMP(:create_time),
                    NOW()
                )
                RETURNING xid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $opposite = $q -> fetch();
        
        $task = [
            ':xid' => $body['xid'],
            ':opposite_xid' => $opposite['xid']
        ];
        
        $sql = "UPDATE wallet_transactions
                SET status = 'DONE',
                    opposite_xid = :opposite_xid,
                    exec_time = NOW()
                WHERE xid = :xid";
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $this -> pdo -> commit();
        
        $body['oppositeXid'] = $opposite['xid'];
        $this -> amqp -> pub(
            'executeTransfer_saga1',
            $body
        );
    }
    
    public function executeTransfer_saga1($body) {
        $th = $this;
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'credit',
            [
                'uid' => $body['dstUser']['uid'],
                'assetid' => $body['asset']['assetid'],
                'amount' => $body['amount'],
                'reason' => 'TRANSFER_RECEIVED',
                'context' => $body['oppositeXid']
            ]
        ) -> then(function() use($th, $body) {
            $th -> amqp -> pub(
                'executeTransfer_saga2',
                $body
            );
        });
    }
    
    public function executeTransfer_saga2($body) {
        $th = $this;
        
        return $th -> amqp -> call(
            'wallet.wallet',
            'commit',
            [
                'lockid' => $body['lockid'],
                'reason' => 'TRANSFER_DONE',
                'context' => $body['xid']
            ]
        ) -> then(function() use($th, $body) {
            $th -> amqp -> pub(
                'mail',
                [
                    'uid' => $body['srcUser']['uid'],
                    'template' => 'transfer_out',
                    'context' => [
                        'asset' => $body['asset']['symbol'],
                        'amount' => $body['amount'],
                        'address' => $body['dstUser']['email'],
                        'memo' => $body['memo'] ? $body['memo'] : '-'
                    ],
                    'email' => $body['srcUser']['email']
                ]
            );
            
            $th -> amqp -> pub(
                'mail',
                [
                    'uid' => $body['dstUser']['uid'],
                    'template' => 'transfer_in',
                    'context' => [
                        'asset' => $body['asset']['symbol'],
                        'amount' => $body['amount'],
                        'address' => $body['srcUser']['email'],
                        'memo' => $body['memo'] ? $body['memo'] : '-'
                    ],
                    'email' => $body['dstUser']['email']
                ]
            );
        });
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
                    'uid' => @$body['dstUid'],
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
                    ':uid' => $srcUser['uid'],
                    ':type' => 'TRANSFER_OUT',
                    ':assetid' => $asset['assetid'],
                    ':amount' => $strAmount,
                    ':status' => 'PENDING',
                    ':address' => $dstUser['email'],
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
                        RETURNING xid,
                                  EXTRACT(epoch FROM create_time) AS create_time';
                
                $q = $th -> pdo -> prepare($sql);
                $q -> execute($task);
                $row = $q -> fetch();
                
                $this -> amqp -> pub(
                    'executeTransfer',
                    [
                        'xid' => $row['xid'],
                        'amount' => $strAmount,
                        'asset' => $asset,
                        'srcUser' => $srcUser,
                        'dstUser' => $dstUser,
                        'memo' => @$body['memo'],
                        'lockid' => $lock['lockid'],
                        'createTime' => $row['create_time']
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