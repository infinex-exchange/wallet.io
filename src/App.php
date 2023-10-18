<?php

require __DIR__.'/Networks.php';
require __DIR__.'/Shards.php';
require __DIR__.'/DepositAddr.php';
require __DIR__.'/Withdrawals.php';
require __DIR__.'/Transactions.php';

require __DIR__.'/API/NetworksAPI.php';
require __DIR__.'/API/DepositAPI.php';
require __DIR__.'/API/WithdrawalAPI.php';
require __DIR__.'/API/TransactionsAPI.php';

use React\Promise;

class App extends Infinex\App\App {
    private $pdo;
    
    private $networks;
    private $shards;
    private $depositAddr;
    private $withdrawals;
    private $transactions;
    
    private $networksApi;
    private $depositApi;
    private $withdrawalApi;
    private $transactionsApi;
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
        
        $this -> shards = new Shards(
            $this -> log,
            $this -> amqp,
            $this -> pdo
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
        
        $this -> transactions = new Transactions(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> networksApi = new NetworksAPI(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> networks
        );
        
        /*$this -> depositApi = new DepositAPI(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> networks,
            $this -> shards,
            $this -> depositAddr
        );
        
        $this -> withdrawalApi = new WithdrawalAPI(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> withdrawals,
            $this -> networks,
            $this -> an
        );
        
        $this -> transactionsApi = new TransactionsAPI(
            $this -> log,
            $this -> amqp,
            $this -> transactions,
            $this -> networks
        );*/
        
        $this -> rest = new Infinex\API\REST(
            $this -> log,
            $this -> amqp,
            [
                $this -> networksApi,
                /*$this -> depositApi,
                $this -> withdrawalApi,
                $this -> transactionsApi*/
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
                    $th -> shards -> start(),
                    $th -> depositAddr -> start(),
                    $th -> withdrawals -> start()
                ]);
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
                return Promise\all([
                    $th -> networks -> stop(),
                    $th -> shards -> stop(),
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