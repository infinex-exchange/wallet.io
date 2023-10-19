<?php

use Infinex\Exceptions\Error;
use function Infinex\Math\trimFloat;
use React\Promise;
use Decimal\Decimal;

class Deposits {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized deposits manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started deposits manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start deposits manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped deposits manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop deposits manager: '.((string) $e));
            }
        );
    }
    
    public function resolveMinDepositAmount($asset, $an) {
        $min = new Decimal(1);
        $min = $min -> shift(-$asset['defaultPrec']);
        
        $minAsset = new Decimal($asset['minDeposit']);
        if($minAsset > $min)
            $min = $minAsset;
        
        $minAn = new Decimal($an['minDeposit']);
        if($minAn > $min)
            $min = $minAn;
        
        return trimFloat($min -> toFixed($asset['defaultPrec']));
    }
}

?>