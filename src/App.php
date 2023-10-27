<?php

require __DIR__.'/Networks.php';
require __DIR__.'/Shards.php';
require __DIR__.'/Nodes.php';
require __DIR__.'/DepositAddr.php';
require __DIR__.'/Withdrawals.php';
require __DIR__.'/Transactions.php';
require __DIR__.'/Deposits.php';
require __DIR__.'/Transfers.php';

require __DIR__.'/API/NetworksAPI.php';
require __DIR__.'/API/DepositAPI.php';
require __DIR__.'/API/WithdrawalAPI.php';
require __DIR__.'/API/TransactionsAPI.php';
require __DIR__.'/API/FeesAPI.php';

use React\Promise;

class App extends Infinex\App\App {
    private $pdo;
    
    private $networks;
    private $shards;
    private $nodes;
    private $depositAddr;
    private $withdrawals;
    private $transactions;
    private $deposits;
    private $transfers;
    
    private $networksApi;
    private $depositApi;
    private $withdrawalApi;
    private $transactionsApi;
    private $feesApi;
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
        
        $this -> nodes = new Nodes(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            OPERATING_TIMEOUT
        );
        $this -> networks -> setNodes($this -> nodes);
        $this -> shards -> setNodes($this -> nodes);
        
        $this -> depositAddr = new DepositAddr(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> withdrawals = new Withdrawals(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> networks,
            $this -> depositAddr
        );
        
        $this -> transactions = new Transactions(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> deposits = new Deposits(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> transfers = new Transfers(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> networksApi = new NetworksAPI(
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> networks,
            $this -> deposits
        );
        
        $this -> depositApi = new DepositAPI(
            $this -> log,
            $this -> amqp,
            $this -> networks,
            $this -> shards,
            $this -> depositAddr,
            $this -> deposits
        );
        
        $this -> withdrawalApi = new WithdrawalAPI(
            $this -> log,
            $this -> amqp,
            $this -> networks,
            $this -> withdrawals
        );
        
        $this -> transactionsApi = new TransactionsAPI(
            $this -> log,
            $this -> amqp,
            $this -> transactions,
            $this -> networks,
            $this -> transfers,
            $this -> withdrawals
        );
        
        $this -> feesApi = new FeesAPI(
            $this -> log,
            $this -> amqp,
            $this -> networks,
            $this -> deposits,
            $this -> withdrawals
        );
        
        $this -> rest = new Infinex\API\REST(
            $this -> log,
            $this -> amqp,
            [
                $this -> networksApi,
                $this -> depositApi,
                $this -> withdrawalApi,
                $this -> transactionsApi,
                $this -> feesApi
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
                    $th -> nodes -> start(),
                    $th -> depositAddr -> start(),
                    $th -> transactions -> start(),
                    $th -> deposits -> start(),
                    $th -> transfers -> start()
                ]);
            }
        ) -> then(
            function() use($th) {
                return $th -> withdrawals -> start();
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
                return $th -> withdrawals -> stop();
            }
        ) -> then(
            function() use($th) {
                return Promise\all([
                    $th -> networks -> stop(),
                    $th -> shards -> stop(),
                    $th -> nodes -> stop(),
                    $th -> depositAddr -> stop(),
                    $th -> transactions -> stop(),
                    $th -> deposits -> stop(),
                    $th -> transfers -> stop()
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