<?php

use Infinex\Exceptions\Error;
use function Infinex\Validation\validateId;
use function Infinex\Validation\validateFloat;
use function Infinex\Math\trimFloat;
use React\Promise;
use Decimal\Decimal;

class Withdrawals {
    private $log;
    private $amqp;
    private $pdo;
    private $networks;
    private $depositAddr;
    
    function __construct($log, $amqp, $pdo, $networks, $depositAddr) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> networks = $networks;
        $this -> depositAddr = $depositAddr;
        
        $this -> log -> debug('Initialized withdrawals manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'validateWithdrawalTarget',
            [$this, 'validateWithdrawalTarget']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started withdrawals manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start withdrawals manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('validateWithdrawalTarget');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped withdrawals manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop withdrawals manager: '.((string) $e));
            }
        );
    }
    
    public function validateWithdrawalTarget($body) {
        if(!isset($body['netid']))
            throw new Error('MISSING_DATA', 'netid');
        if(!is_string($body['netid']))
            throw new Error('VALIDATION_ERROR', 'netid');
        
        if(!isset($body['address']) && !isset($body['memo']))
            throw new Error('MISSING_DATA', 'At least one is required: address or memo', 400);
        if(isset($body['address']) && !is_string($body['address']))
            throw new Error('VALIDATION_ERROR', 'address', 400);
        if(isset($body['memo']) && !is_string($body['memo']))
            throw new Error('VALIDATION_ERROR', 'memo', 400);
        
        $resp = [
            'validAddress' => null,
            'validMemo' => null,
            'internal' => null
        ];
        
        if(isset($body['memo'])) {
            $network = $this -> networks -> getNetwork([
                'netid' => $body['netid']
            ]);
            
            if($network['memoName'] === null)
                throw new Error('CONFLICT', 'Network does not support memo but memo provided', 409);
            
            //$resp['validMemo'] = true; // TODO
        }
        
        if(isset($body['address'])) {
            try {
                $address = $this -> depositAddr -> getDepositAddress([
                    'netid' => $body['netid'],
                    'address' => $body['address']
                ]);
                
                $resp['internal'] = true;
            }
            catch(Error $e) {
                if($e -> getStrCode() != 'NOT_FOUND')
                    throw $e;
                
                $resp['internal'] = false;
            }
            
            //$resp['validAddress'] = true; // TODO
        }
        
        // -------- TODO: so bad call to legacy api ------------
        $network = $this -> networks -> getNetwork([
            'netid' => $body['netid']
        ]);
        return $this -> amqp -> call(
            'account.account',
            'getSession',
            [ 'sid' => 46054 ]
        ) -> then(function($session) use($resp, $body, $network) {
           $bodyLegacy = [
                'api_key' => $session['apiKey'],
                'network' => $network['netid'],
                'asset' => $network['nativeAssetid']
            ];
            if(isset($body['memo']))
                $bodyLegacy['memo'] = $body['memo'];
            if(isset($body['address']))
                $bodyLegacy['address'] = $body['address'];
            return $this -> amqp -> call(
                'temp.legacy-api',
                'rest',
                [
                    'method' => 'POST',
                    'path' => '/wallet/withdraw/validate',
                    'query' => [],
                    'body' => $bodyLegacy,
                    'auth' => null,
                    'userAgent' => 'wallet.io',
                    'ip' => '127.0.0.1'
                ]
            ) -> then(
                function($respLegacy) use($resp) {
                    if($respLegacy['status'] != 200 || @$respLegacy['body']['success'] != true)
                        throw new Error('UNKNOWN', 'Legacy API error', 500);
                    $resp['validMemo'] = @$respLegacy['body']['valid_memo'];
                    $resp['validAddress'] = @$respLegacy['body']['valid_address'];
                    return $resp;
                }
            );
        });
        // -----------------------------------------------------
        
        //return $resp;
    }
    
    public function createWithdrawal($body) {
        $th = $this;
        
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid');
        if(!isset($body['amount']))
            throw new Error('MISSNIG_DATA', 'amount', 400);
        
        if(!validateId($body['uid']))
            throw new Error('VALIDATION_ERROR', 'uid');
        if(!validateFloat($body['amount']))
            throw new Error('VALIDATION_ERROR', 'amount', 400);
        
        if(isset($body['fee']) && !validateFloat($body['fee']))
            throw new Error('VALIDATION_ERROR', 'fee', 400);
        if(isset($body['validateOnly']) && !is_bool($body['validateOnly']))
            throw new Error('VALIDATION_ERROR', 'validateOnly');
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'getAsset',
            [
                'assetid' => @$body['assetid'],
                'assetSymbol' => @$body['symbol']
            ]
        ) -> then(function($asset) use($th, $body) {
            if(!$asset['enabled'])
                throw new Error('FORBIDDEN', 'Asset '.$asset['symbol'].' is out of service', 403);
            
            // Get AN info
            $an = $th -> networks -> getAnPair([
                'networkSymbol' => @$body['networkSymbol'],
                'netid' => @$body['netid'],
                'assetid' => $asset['assetid']
            ]);
            
            if(!$an['network']['enabled'])
                throw new Error('FORBIDDEN', 'Network '.$an['network']['name'].' is out of service', 403);
            
            if($an['network']['blockWithdrawalsMsg'] !== null)
                throw new Error('FORBIDDEN', $network['blockWithdrawalsMsg'], 403);
            
            if(!$an['enabled'])
                throw new Error(
                    'FORBIDDEN',
                    'Network '.$an['network']['name'].' is out of service for '.$asset['symbol'],
                    403
                );
            
            if($an['blockWithdrawalsMsg'] !== null)
                throw new Error('FORBIDDEN', $an['blockWithdrawalsMsg'], 403);
        
            $minAmount = $th -> resolveMinWithdrawalAmount($asset, $an);
            
            // Check amount
            
            $dAmount = new Decimal($body['amount']);
            $dAmount = $dAmount -> round($an['prec'], Decimal::ROUND_TRUNCATE);
            if($dAmount < $minAmount)
                throw new Error('AMOUNT_OUT_OF_RANGE', 'Withdrawal amount is less than minimal amount', 416);
            
            // Validate target
            
            return $th -> validateWithdrawalTarget([
                'netid' => $an['network']['netid'],
                'address' => @$body['address'],
                'memo' => @$body['memo']
            ]) -> then(function($valResp) use($th, $body, $asset, $an, $dAmount) {
                // Check validation result
                
                if(!$valResp['validAddress'])
                    throw new Error('VALIDATION_ERROR', 'Address is invalid', 400);
                
                if($valResp['validMemo'] === false) // null is ok
                    throw new Error('VALIDATION_ERROR', 'Memo is invalid', 400);
                
                // Check fee
                
                if($valResp['internal']) {
                    $dFee = new Decimal(0);
                    $strFee = '0';
                }
            
                else {
                    $feeRange = $th -> resolveFeeRange($an);
                    
                    if(isset($body['fee']))
                        $dFee = new Decimal($body['fee']);
                
                    else {
                        $dFee = new Decimal($feeRange['min']);
                        $dFee += $feeRange['max'];
                        $dFee /= 2;
                    }
                    
                    if($feeRange['prec'] > $an['prec'])
                        throw new Error(
                            'DATA_INTEGRITY_ERROR',
                            'Fee prec > asset in network prec, prevented rounding error'
                        );
                    
                    $dFee = $dFee -> round($feeRange['prec'], Decimal::ROUND_TRUNCATE);
            
                    if($dFee < $feeRange['min'] || $dFee > $feeRange['max'])
                        throw new Error('FEE_OUT_OF_RANGE', 'Withdrawal fee is out of allowed range', 416);
                    
                    $strFee = trimFloat($dFee -> toFixed($feeRange['prec']));
                }
                
                // validateOnly ends here
                if(@$body['validateOnly'])
                    return;
                
                // Get lock amount
                $strAmount = $dAmount -> trimFloat(toFixed($an['prec']));
                
                $dLockAmount = $dAmount + $dFee;
                $strLockAmount = trimFloat($dLockAmount -> toFixed($an['prec']))
                
                // Create withdrawal
                
                // -------- TODO: so bad call to legacy api ------------
                return $this -> amqp -> call(
                    'account.account',
                    'getSession',
                    [ 'sid' => @$body['_sid'] ]
                ) -> then(function($session) use($th, $body, $asset, $an, $strAmount, $strFee) {
                   $bodyLegacy = [
                        'api_key' => $session['apiKey'],
                        'network' => $an['network']['netid'],
                        'asset' => $asset['assetid'],
                        'address' => $body['address'],
                        'amount' => $strAmount,
                        'fee' => $strFee
                    ];
                    if(isset($body['memo']))
                        $bodyLegacy['memo'] = $body['memo'];
                    return $this -> amqp -> call(
                        'temp.legacy-api',
                        'rest',
                        [
                            'method' => 'POST',
                            'path' => '/wallet/withdraw',
                            'query' => [],
                            'body' => $bodyLegacy,
                            'auth' => null,
                            'userAgent' => 'wallet.io',
                            'ip' => '127.0.0.1'
                        ]
                    ) -> then(
                        function($respLegacy) {
                            if($respLegacy['status'] != 200 || @$respLegacy['body']['success'] != true)
                                throw new Error('UNKNOWN', 'Legacy API error: '.@$respLegacy['body']['error'], 500);
                            return ['xid' => $respLegacy['body']['xid']];
                        }
                    );
                });
                // -----------------------------------------------------
            });
        });
    }
    
    public function cancelWithdrawal($body) {
        $th = $this;
        
        if(!isset($body['xid']))
            throw new Error('MISSNIG_DATA', 'xid', 400);
        
        if(!validateId($body['xid']))
            throw new Error('VALIDATION_ERROR', 'xid', 400);
                
        // -------- TODO: so bad call to legacy api ------------
        return $this -> amqp -> call(
            'account.account',
            'getSession',
            [ 'sid' => @$body['_sid'] ]
        ) -> then(function($session) use($th, $body) {
           $bodyLegacy = [
                'api_key' => $session['apiKey'],
                'xid' => $body['xid']
            ];
            return $this -> amqp -> call(
                'temp.legacy-api',
                'rest',
                [
                    'method' => 'POST',
                    'path' => '/wallet/withdraw/cancel',
                    'query' => [],
                    'body' => $bodyLegacy,
                    'auth' => null,
                    'userAgent' => 'wallet.io',
                    'ip' => '127.0.0.1'
                ]
            ) -> then(
                function($respLegacy) {
                    if($respLegacy['status'] != 200 || @$respLegacy['body']['success'] != true)
                        throw new Error('UNKNOWN', 'Legacy API error: '.@$respLegacy['body']['error'], 500);
                }
            );
        });
        // -----------------------------------------------------
    }
    
    public function resolveMinWithdrawalAmount($asset, $an) {
        $min = new Decimal(1);
        $min = $min -> shift(-$asset['defaultPrec']);
        
        $minAsset = new Decimal($asset['minWithdrawal']);
        if($minAsset > $min)
            $min = $minAsset;
        
        $minAn = new Decimal($an['minWithdrawal']);
        if($minAn > $min)
            $min = $minAn;
        
        return $min;
    }
    
    public function resolveFeeRange($an) {
        $dFeeBase = new Decimal($an['wdFeeBase']);
            
        $dFeeMin = new Decimal($an['wdFeeMin']);
        $dFeeMin += $dFeeBase;
        
        $dFeeMax = new Decimal($an['wdFeeMax']);
        $dFeeMax += $dFeeBase;
        
        $minPrec = $this -> countDecimalPlaces($dFeeMin -> toFixed($an['prec']));
        $maxPrec = $this -> countDecimalPlaces($dFeeMax -> toFixed($an['prec']));
        
        return [
            'min' => $dFeeMin,
            'max' => $dFeeMax,
            'prec' => max($minPrec, $maxPrec)
        ];
    }
    
    private function countDecimalPlaces($dstr) {
        $exp = explode('.', trimFloat($dstr));
        if(!isset($exp[1]))
            return 0;
        return strlen($exp[1]);
    }
}

?>