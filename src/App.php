<?php

require __DIR__.'/DepositAddr.php';
require __DIR__.'/Withdrawals.php';

use React\Promise;

class App extends Infinex\App\App {
    private $pdo;
    
    private $depoAddr;
    private $wd;
    
    function __construct() {
        parent::__construct('wallet.io');
        
        $this -> pdo = new Infinex\Database\PDO(
            $this -> loop,
            $this -> log,
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME
        );
        
        $this -> depoAddr = new DepositAddr(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> wd = new Withdrawals(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
    }
    
    public function start() {
        $th = $this;
        
        parent::start() -> then(
            function() use($th) {
                return $th -> pdo -> start();
            }
        ) -> then(
            function() use($th) {
                return Promise\all([
                    $th -> depoAddr -> start(),
                    $th -> wd -> start()
                ]);
            }
        ) -> catch(
            function($e) {
                $th -> log -> error('Failed start app: '.((string) $e));
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        Promise\all([
            $th -> depoAddr -> stop(),
            $th -> wd -> stop()
        ]) -> then(
            function() use($th) {
                return $th -> pdo -> stop();
            }
        ) -> then(
            function() use($th) {
                $th -> parentStop();
            }
        );
    }
    
    private function parentStop() {
        parent::stop();
    }
}

?>