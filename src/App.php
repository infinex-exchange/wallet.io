<?php

require __DIR__.'/Networks.php';
require __DIR__.'/AssetNetwork.php';
require __DIR__.'/DepositAddr.php';
require __DIR__.'/Withdrawals.php';

require __DIR__.'/API/NetworksAPI.php';
require __DIR__.'/API/DepositAPI.php';
require __DIR__.'/API/WithdrawalAPI.php';

use React\Promise;

class App extends Infinex\App\App {
    private $pdo;
    
    private $networks;
    private $an;
    private $depositAddr;
    private $withdrawals;
    
    private $networksApi;
    private $depositApi;
    private $withdrawalApi;
    private $rest;
    
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
        
        $this -> networks = new Networks(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> an = new AssetNetwork(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> networks
        );
        
        $this -> depositAddr = new DepositAddr(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> withdrawals = new Withdrawals(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> networksApi = new NetworksAPI(
            $this -> log,
            $this -> pdo,
            $this -> networks,
            $this -> an
        );
        
        $this -> depositApi = new DepositAPI(
            $this -> log,
            $this -> pdo,
            $this -> depositAddr,
            $this -> an
        );
        
        $this -> withdrawalApi = new WithdrawalAPI(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> withdrawals,
            $this -> networks,
            $this -> an
        );
        
        $this -> rest = new Infinex\API\REST(
            $this -> log,
            $this -> amqp,
            [
                $this -> networksApi,
                $this -> depositApi,
                $this -> withdrawalApi
            ]
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
                    $th -> networks -> start(),
                    $th -> depositAddr -> start(),
                    $th -> withdrawals -> start()
                ]);
            }
        ) -> then(
            function() use($th) {
                return $th -> an -> start();
            }
        ) -> then(
            function() use($th) {
                return $th -> rest -> start();
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed start app: '.((string) $e));
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $th -> rest -> stop() -> then(
            function() use($th) {
                return $th -> an -> stop();
            }
        ) -> then(
            function() use($th) {
                return Promise\all([
                    $th -> networks -> stop(),
                    $th -> depositAddr -> stop(),
                    $th -> withdrawals -> stop()
                ]);
            }
        ) -> then(
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